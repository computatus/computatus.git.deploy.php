<?php
namespace Computatus\GitDeploy;

use RuntimeException;

final class ShellExecutor {

  public static function run(string $cmd, array $env = [], int $timeout = 30): object {
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $proc = proc_open($cmd, $desc, $pipes, null, $env);
    if (!is_resource($proc)) throw new RuntimeException("Failed to start process: $cmd");

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $start = time();

    while (true) {
      $stdout .= stream_get_contents($pipes[1]);
      $stderr = stream_get_contents($pipes[2]);
      $status = proc_get_status($proc);

      if (!$status['running']) break;
      if (time() - $start > $timeout) {
        proc_terminate($proc, 9);
        $stdout .= "\n[TIMEOUT] Process exceeded {$timeout}s.\n";
        break;
      }

      usleep(100000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $stdout = explode("\n", trim($stdout));
    $stderr = explode("\n", trim($stderr));

    foreach ($stdout as $key => $line) if (empty($line)) unset($stdout[$key]);
    foreach ($stderr as $key => $line) if (empty($line)) unset($stderr[$key]);

    return (object)[
      'out' => $stdout,
      'err' => $stderr
    ];
  }

}
