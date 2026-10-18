<?php
require __DIR__ . '/../vendor/autoload.php';
$http = (new think\App())->http;
$response = $http->run();
$response->send();
$http->end($response);