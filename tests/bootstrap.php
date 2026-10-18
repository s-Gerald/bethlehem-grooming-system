<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Laravel reads $_SERVER before $_ENV. Honor PHPUnit's forced values even
// when PHP inherited conflicting local settings at process startup.
$_SERVER = array_replace($_SERVER, $_ENV);
