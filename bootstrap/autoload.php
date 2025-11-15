<?php
$rootPath = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';

$composerAutoload = $rootPath . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class) use ($rootPath): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $relative = substr($class, 4);
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    $fullPath = $rootPath . '/app/' . $relativePath;

    if (is_file($fullPath)) {
        require_once $fullPath;
    }
});
