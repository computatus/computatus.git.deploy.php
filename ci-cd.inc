<?php

$contents = file_get_contents('php://input') ?? $_POST;
$contents = empty($contents) ? $_POST : $contents;
$data = json_decode($contents);
$repository = $data->repository ?? null;

try {
  $baseDir = env('REPOSITORY_BASEDIR');
  $gitRepos = findGitRepositories($baseDir);
  foreach ($gitRepos as $k => $gitRepo)
    $gitRepos[$k] = findGitRepositoryInfo($gitRepo);
  if ($repository) {
    processDeploy($repository, $gitRepos, function($url) {
      $path = getcwd();
      $user = str_replace(["\n","\r"], '', shell_exec('whoami'));
      echo "[server] current user: \"$user\"\n";
      echo "[server] current path: \"$path\"\n";
      echo "[server/git] output: \n";
      echo shell_exec(sprintf('git checkout %s', 'master'));
      echo shell_exec(sprintf('git pull %s 2>&1', $url));
      echo "[server/deploy.sh] output: \n";
      echo shell_exec('sh deploy.sh 2>&1');
      echo "[server] process finished!\n";
    });
  }
  else {
    //header('HTTP/1.0 404 Not Found');
    //http_response_code(404);
    echo "404 - repository not found!\n";
  }
}
catch (Exception $e) {
  echo 'Error: '.$e->getMessage()."\n";
}



#==<-Functions->===============================================================

function env(string $key): ?string {
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
    $hash = env('REPOSITORY_ACCESS_KEY');
    if ($hash) {
      foreach ($repos as $repo) {
        $path = "$repo->path/deploy.sh";
        echo "checking \"$path\"...\n";
        if (realpath($path) && chdir($repo->path)) {
          echo "[deploy] \"$path\" repository found!\n";
          $origin = null;
          foreach ($repo->remotes as $remote)
            if ($remote->type == 'push') $origin = $remote;
          if ($origin) {
            echo "[origin] \"$origin->domain/$origin->owner/$repo->name\" found!\n";
            $url = "https://$hash@$origin->domain/$origin->owner/$repo->name";
            if ($callback) $callback($url);
          }
          else echo "repository:origin not found\n";
        }
        else echo "deploy.sh not found!\n";
      }
    }
    else echo "repository:access_key not found\n";
  }
}