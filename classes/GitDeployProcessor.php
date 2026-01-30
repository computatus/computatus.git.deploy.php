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
        while(ob_get_level()) ob_end_flush();
        ob_implicit_flush();

        $cmd = 'stdbuf -oL -eL ./deploy.sh';
        $descriptorSpec = [
          1 => ['pipe', 'w'],
          2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
          echo "[deploy.sh] Failed to start process\n";
          exit;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while(true) {
          $stdout = stream_get_contents($pipes[1]);
          $stderr = stream_get_contents($pipes[2]);

          if ($stdout !== '') {
            foreach (explode("\n", trim($stdout)) as $line) {
              if ($line !== '') echo "[deploy.sh] $line\n";
            }
          }

          if ($stderr !== '') {
            foreach (explode("\n", trim($stderr)) as $line) {
              if ($line !== '') echo "[deploy.sh][ERR] $line\n";
            }
          }

          flush();

          $status = proc_get_status($process);
          if (!$status['running']) break;

          usleep(100000);

        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
          echo "[deploy.sh] deploy.sh exited with status $exitCode\n\n";
          exit;
        }
        echo "[deploy.sh] Deploy finished successfully\n\n";
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
