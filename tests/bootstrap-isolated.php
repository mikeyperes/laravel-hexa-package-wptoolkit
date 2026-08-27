<?php

$packageRoot = dirname(__DIR__);
$prefix = 'hexa_package_wptoolkit\\';

spl_autoload_register(static function (string $class) use ($packageRoot, $prefix): void {
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = $packageRoot.'/src/'.$relative.'.php';
    if (is_file($path)) {
        require_once $path;
    }
}, true, true);
