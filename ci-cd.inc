<?php
namespace Computatus\GitDeploy;

http_response_code(202);
ob_end_flush();

include 'helpers.php';
include 'classes/EnvLoader.php';
include 'classes/GitDeployer.php';
include 'classes/GitDeployProcessor.php';
include 'classes/GitRemote.php';
include 'classes/GitRepository.php';
include 'classes/GitRepositoryFinder.php';
include 'classes/ShellExecutor.php';


$deployer = new GitDeployer(__DIR__.'/.env');
$deployer->handleRequest();
