<?php

$autoload_path =  __DIR__ . "/../vendor/autoload.php";
$loader = require($autoload_path);

$loader->add('CentralDesktop\Parallel\Test', __DIR__);
