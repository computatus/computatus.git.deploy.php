<?php

$contents = file_get_contents('php://input') ?? $_REQUEST;
$contents = empty($contents) ? (object) $_REQUEST : $contents;
$data = is_string($contents) ? json_decode($contents) : $contents;
$repository = $data->repository ?? null;

try {
  $baseDir = env('REPOSITORY_BASEDIR');
  $baseDir = realpath($baseDir);
  $gitRepos = findGitRepositories($baseDir);
  foreach ($gitRepos as $k => $gitRepo)
    $gitRepos[$k] = findGitRepositoryInfo($gitRepo);
  if ($repository) {
    processDeploy($repository, $gitRepos, function() {
      $path = getcwd();
      $user = str_replace(["\n","\r"], '', shell_exec('whoami'));
      echo "[server] current user: \"$user\"\n";
      echo "[server] current path: \"$path\"\n";
      echo "[server] process finished!\n";
    });
  }
  else NotFound();
}
catch (Exception $e) {
  header('HTTP/1.1 500 Internal Server Error');
  header('Content-Type: application/json');
  echo "error: {$e->getMessage()}\n";
}



#==<-Functions->===============================================================

/**
 * @throws Exception
 */
function env(string $key): ?string {
  if (!file_exists(__DIR__.'/.env'))
    throw new Exception("'.env' could not be found.");
  $contents = file_get_contents(__DIR__.'/.env');
  $lines = explode("\n", $contents);
  $props = [];
  foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, '#') === 0) continue;
    $line = explode('=', $line);
    if (count($line)) $props[$line[0]] = $line[1] ?? null;
  }
  return $props[$key] ?? null;
}

function findGitRepositories(string $baseDir): array {
  $repositories = [];

  // Check if base directory exists
  if (!is_dir($baseDir))
    throw new InvalidArgumentException('The base dir does not exists!');

  // Create an iterator to traverse all directories within the base directory
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $path) {
    // Check if path is a directory named .git
    if ($path->isDir() && basename($path) === '.git') {
      // Get the Git repository path
      $repositories[] = dirname($path->getPathname());
    }
  }

  return $repositories;
}

function findGitRepositoryInfo(string $path): object {
  $info = (object)['path' => $path];

  // Check if directory is a valid and contains .git path
  if (!is_dir("$path/.git")) {
    throw new InvalidArgumentException(
      "The repository does not a git repository: '$path'");
  }

  // Get the repository name
  $info->name = basename($path);

  // Get the repository configured remotes
  $command = "git -C " . escapeshellarg($path) . " remote -v";
  exec($command, $output, $status);
  if ($status !== 0) throw new RuntimeException("Error on get repository remotes: $path");

  foreach ($output as $line) {
    if (preg_match('/^(\S+)\s+(\S+)\s+\((fetch|push)\)$/', $line, $matches)) {
      $extracted = extractRepoFullName($matches[2]);
      $info->remotes[] = (object)[
        'name' => $matches[1],
        'type' => $matches[3],
        'url' => $matches[2],
        'domain' => $extracted->domain ?? null,
        'owner' => $extracted->owner ?? null,
      ];
    }
  }
  return $info;
}

function extractRepoFullName(string $url): ?object {
  $url = preg_replace('/\.git$/', '', $url);
  $pattern = "/github\\.com\\/([^\\/]+)\\/([^\\/]+)/";
  if (preg_match($pattern, $url, $matches)) {
    [$domain, $owner, $name] = $matches;
    return (object) [
      'domain' => 'github.com',
      'owner' => $owner,
      'name' => $name
    ];
  }
  $pattern = "/gitlab\\.com\\/([^\\/]+)\\/([^\\/]+)/";
  if (preg_match($pattern, $url, $matches)) {
    [$domain, $owner, $name] = $matches;
    return (object) [
      'domain' => 'gitlab.com',
      'owner' => $owner,
      'name' => $name
    ];
  }
  return null;
}

function findGitRepositoryByName(string $repo, array $repos): array {
  return array_filter($repos, function($repository) use ($repo) {
    foreach ($repository->remotes as $remote)
      if ($repo === "$remote->owner/$repository->name") return true;
    return false;
  });
}

function processDeploy(string $repository, array $gitRepos, $callback) {
  if ($repos = findGitRepositoryByName($repository, $gitRepos)) {
    $repos = array_values($repos);
    foreach ($repos as $repo) {

      putenv("HOME=$repo->path");
      putenv("GIT_SSH_COMMAND=ssh -i $repo->path/.ssh/github_deploy -o StrictHostKeyChecking=no");
      exec('git pull 2>&1', $output, $returnCode);
      if ($returnCode !== 0) throw new Error("❌ steps fail (code $returnCode)\n");

      header('Content-Type: text/plain');
      $path = "$repo->path/deploy.sh";
      echo "checking \"$path\"...\n";
      if (realpath($path) && chdir($repo->path)) {
        echo "[deploy] \"$path\" repository found!\n";
        if ($callback) $callback();
      }
      else echo "deploy.sh not found!\n";
    }
  }
  else NotFound();
}

function dd(...$argv) {
  header('Content-Type: application/json');
  echo json_encode(count($argv) === 1 ? $argv[0] : $argv);
  die();
}

function NotFound() {
  header('HTTP/1.1 404 Not Found');
  header('Content-Type: application/json');
  echo "404 - repository not found!\n";
  die();
}