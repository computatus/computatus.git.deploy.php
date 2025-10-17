<?php

/**
 * @param ...$argv
 * @return void
 */
function dd(...$argv): void {
  ob_clean();
  if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  echo json_encode(
    count($argv) === 1 ? $argv[0] : $argv,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
