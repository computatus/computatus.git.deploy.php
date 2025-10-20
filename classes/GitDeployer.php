<?php
namespace Computatus\GitDeploy;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GitDeployer {

  private EnvLoader $env;
  private GitRepositoryFinder $finder;


  public function __construct(string $envPath) {
    $this->env = new EnvLoader($envPath);
    $this->finder = new GitRepositoryFinder();
  }

  public function handleRequest(): void {
    try {
      $data = $this->getRequestData();
      $repository = $data->repository ?? null;

      if (!$repository) {
        http_response_code(404);
        echo "404 - repository not found!\n";
        return;
      }

      $baseDir = $this->env->get('REPOSITORY_BASEDIR');
      if (!$baseDir) throw new RuntimeException("Missing REPOSITORY_BASEDIR in .env");

      $repos = $this->finder->find($baseDir);
      $repos = array_map([$this->finder, 'getInfo'], $repos);

      $deploy = new GitDeployProcessor($repos);
      $deploy->process($repository);
    }
    catch (Throwable $e) {
      http_response_code(500);
      echo "Error: {$e->getMessage()}\n";
    }
  }

  private function getRequestData(): object {
    $raw = file_get_contents('php://input') ?: json_encode($_REQUEST) ?: json_encode($_POST);
    $data = json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE)
      throw new InvalidArgumentException('Invalid JSON input data');
    return $data ?: (object)[];
  }

}
