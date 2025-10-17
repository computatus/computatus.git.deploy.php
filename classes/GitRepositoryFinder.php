<?php
namespace Computatus\GitDeploy;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class GitRepositoryFinder {

  public function find(string $baseDir): array {
    if (!is_dir($baseDir)) throw new InvalidArgumentException("Base directory not found: $baseDir");

    $repositories = [];
    $flags = FilesystemIterator::SKIP_DOTS;
    $mode = RecursiveIteratorIterator::SELF_FIRST;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, $flags), $mode);

    foreach ($iterator as $path) {
      if ($path->isDir() && basename($path) === '.git') $repositories[] = dirname($path->getPathname());
    }

    return $repositories;
  }

  public function getInfo(string $path): GitRepository {
    if (!is_dir("$path/.git")) throw new InvalidArgumentException("Not a Git repository: $path");

    $repo = new GitRepository($path, basename($path));
    exec("git -C " . escapeshellarg($path) . " remote -v", $output, $status);
    if ($status !== 0) throw new RuntimeException("Error getting remotes for repository: $path");

    foreach ($output as $line) {
      if (preg_match('/^(\S+)\s+(\S+)\s+\((fetch|push)\)$/', $line, $m)) {
        $remote = GitRemote::fromUrl($m[1], $m[2], $m[3]);
        $repo->remotes[] = $remote;
      }
    }

    return $repo;
  }

}
