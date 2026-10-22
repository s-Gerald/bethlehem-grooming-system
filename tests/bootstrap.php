<?php

if (PHP_VERSION_ID < 80400) {
    throw new RuntimeException('The test suite requires PHP 8.4 or newer; running '.PHP_VERSION.' at '.PHP_BINARY.'. Select the PHP 8.4 CLI described in README.md.');
}

require dirname(__DIR__).'/vendor/autoload.php';

// Laravel reads $_SERVER before $_ENV. Honor PHPUnit's forced values even
// when PHP inherited conflicting local settings at process startup.
$_SERVER = array_replace($_SERVER, $_ENV);
