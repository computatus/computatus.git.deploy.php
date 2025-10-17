<?php
namespace Computatus\GitDeploy;

final class GitRemote {

  public string $name;
  public string $url;
  public string $type;
  public ?string $domain = null;
  public ?string $owner = null;
  public ?string $repoName = null;


  public static function fromUrl(string $name, string $url, string $type): self {
    $instance = new self();
    $instance->name = $name;
    $instance->url = $url;
    $instance->type = $type;

    $cleanUrl = str_replace(['git@', ':'], ['', '/'], $url);
    $cleanUrl = preg_replace('/\.git$/', '', $cleanUrl);

    if (preg_match('#(github|gitlab)\.com/([^/]+)/([^/]+)#', $cleanUrl, $m)) {
      $instance->domain = "{$m[1]}.com";
      $instance->owner = $m[2];
      $instance->repoName = $m[3];
    }

    return $instance;
  }

}
