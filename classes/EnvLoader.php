<?php
namespace Computatus\GitDeploy;

use RuntimeException;

final class EnvLoader {

  private array $vars = [];


  public function __construct(string $path) {
    if (!is_file($path)) throw new RuntimeException("'.env' file not found at: '$path'");
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) continue;
      [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
      $this->vars[trim($key)] = trim($value);
    }
  }

  public function get(string $key): ?string {
    return $this->vars[$key] ?? null;
  }

}
