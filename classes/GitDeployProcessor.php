<?php
namespace Computatus\GitDeploy;

final class GitDeployProcessor {

  /** @var GitRepository[] */
  private array $repositories;


  public function __construct(array $repositories) {
    $this->repositories = $repositories;
  }

  public function process(string $repositoryName): void {
    $matches = $this->findByName($repositoryName);
    $user = str_replace(["\n", "\r"], '', shell_exec('whoami'));

    if (empty($matches)) {
      echo "404 - repository not found!\n";
      return;
    }

    echo "[server] current user: \"$user\"\n";
    echo "[server] current path: \"" . getcwd() . "\"\n\n\n";

    foreach ($matches as $repo) {

      echo "[project] current path: {$repo->path}\n\n";

      $sshKey = "{$repo->path}/.ssh/github_deploy";
      if (!is_file($sshKey)) {
        echo "⚠️ SSH key not found: $sshKey\n";
        continue;
      }

      $cmd = sprintf('git -C %s pull deploy master --ff-only 2>&1', escapeshellarg($repo->path));
      $output = ShellExecutor::run($cmd, [
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'HOME' => $repo->path,
        'GIT_SSH_COMMAND' => sprintf('ssh -i %s -o IdentitiesOnly=yes -o StrictHostKeyChecking=no',
          escapeshellarg($sshKey)
        ),
      ]);

      if (!empty($output->err)) {
        foreach ($output->err as $err) echo "[git/update] err: $err\n";
        exit;
      }
      foreach ($output->out as $out) echo "[git/update] out: $out\n";
      echo "\n";

      $deployScriptPath = "$repo->path/deploy.sh";
      if (realpath($deployScriptPath) && chdir($repo->path)) {
        exec('./deploy.sh', $output, $status);
        if ($status !== 0) {
          echo "[deploy.sh] deploy.sh exited with status $status\n";
          foreach ($output as $line) echo "[deploy.sh] $line\n";
          exit;
        }

        foreach ($output as $line) echo "[deploy.sh] $line\n";

      }
      else echo "[deploy.sh] deploy.sh not found!\n\n";
    }
  }

  private function findByName(string $repoName): array {
    $arr = array_filter($this->repositories, function (GitRepository $repo) use ($repoName) {
      foreach ($repo->remotes as $remote)
        if ("{$remote->owner}/{$repo->name}" === $repoName) return true;
      return false;
    });
    return array_values($arr);
  }

}
