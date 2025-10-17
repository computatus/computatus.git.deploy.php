<?php
namespace Computatus\GitDeploy;

final class GitRepository {

  public string $path;
  public string $name;
  public array $remotes = [];


  public function __construct(string $path, string $name) {
    $this->path = $path;
    $this->name = $name;
  }

}
