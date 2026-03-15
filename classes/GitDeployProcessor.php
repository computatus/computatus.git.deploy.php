<?php
namespace Computatus\GitDeploy;

final class GitDeployProcessor {

  /** @var GitRepository[] */
  private array $repositories;

  private EnvLoader $env;


  public function __construct(array $repositories, EnvLoader $env, private object $data) {
    $this->repositories = $repositories;
    $this->env = $env;
  }

  public function process(string $repositoryName): void {
    $matches = $this->findByName($repositoryName);
    $user = str_replace(["\n", "\r"], '', shell_exec('whoami'));

    if (empty($matches)) {
      http_response_code(404);
      header('HTTP/1.0 404 Not Found');
      header('Content-Type: text/plain');
      echo "404 - repository not found!\n";
      return;
    }

    header('Content-Type: text/plain');
    echo "[server] current user: \"$user\"\n";
    echo "[server] current path: \"" . getcwd() . "\"\n\n\n";

    foreach ($matches as $repo) {

      $currentPath = realpath($repo->path);
      echo "[project] current path: {$currentPath}\n\n";
      
      if ($this->canStep('git')) {
        
        $sshKey = "{$repo->path}/.ssh/github_deploy";
        if (!is_file($sshKey)) {
          echo "⚠️ SSH key not found: $sshKey\n";
          continue;
        }
        
        $gitPath = $this->env->get('GIT_PATH') ?? 'git';
        $cmd = sprintf('"%s" -C %s pull deploy master --ff-only 2>&1', $gitPath, escapeshellarg($currentPath));

        $output = ShellExecutor::run($cmd, [
          'PATH' => '/usr/local/bin:/usr/bin:/bin',
          'HOME' => $repo->path,
          'GIT_SSH_COMMAND' => sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o StrictHostKeyChecking=no',
            escapeshellarg($sshKey)
          ),
        ]);

        if (!empty($output->err)) {
          foreach ($output->err as $err) echo "[git/update] err: $err\n";
          exit;
        }
        
        foreach ($output->out as $out) echo "[git/update] out: $out\n";
        echo "\n";

      }

      $isWindows = PHP_OS_FAMILY === 'Windows';

      $deployScriptExt = $isWindows ? 'bat' : 'sh';
      $deployScriptPath = "$repo->path/deploy.{$deployScriptExt}";
      if (realpath($deployScriptPath) && chdir($repo->path)) {
        while(ob_get_level()) ob_end_flush();
        ob_implicit_flush();

        $args  = "--environment={$this->env->get('ENVIRONMENT')} ";
        $args .= "--mode=deploy ";
        $args .= isset($this->data->step) ? "--step={$this->data->step}" : '';

        $extraCmd = !$isWindows ? 'stdbuf -oL -eL ./' : '';
        $cmd = "{$extraCmd}deploy.{$deployScriptExt} $args";
        $descriptorSpec = [
          1 => ['pipe', 'w'],
          2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
          echo "[deploy.$deployScriptExt] Failed to start process\n";
          exit;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while(true) {
          $stdout = stream_get_contents($pipes[1]);
          $stderr = stream_get_contents($pipes[2]);

          if ($stdout !== '') {
            foreach (explode("\n", trim($stdout)) as $line) {
              if ($line !== '') echo "[deploy.$deployScriptExt] $line\n";
            }
          }

          if ($stderr !== '') {
            foreach (explode("\n", trim($stderr)) as $line) {
              if ($line !== '') echo "[deploy.$deployScriptExt][ERR] $line\n";
            }
          }

          flush();

          $status = proc_get_status($process);
          if (!$status['running']) break;

          usleep(100000);

        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
          echo "[deploy.$deployScriptExt] deploy.$deployScriptExt exited with status $exitCode\n\n";
          exit;
        }
        echo "[deploy.$deployScriptExt] Deploy finished successfully\n\n";
      }
      else echo "[deploy.$deployScriptExt] deploy.$deployScriptExt not found!\n\n";
    }
  }

  private function findByName(string $repoName): array {
    $arr = array_filter($this->repositories, function (GitRepository $repo) use ($repoName) {
      foreach ($repo->remotes as $remote) {
        if ("{$remote->owner}/{$repo->name}" === $repoName) return true;
      }
      return false;
    });
    return array_values($arr);
  }

  private function canStep(string $step): bool {
    return (!isset($this->data->step) 
      || $this->data->step === null 
      || $this->data->step === '' 
      || $this->data->step === $step);
  }

}
