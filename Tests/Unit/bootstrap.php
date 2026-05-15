<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 *
 * PHPUnit/Pest bootstrap for DeployEcommerce_PreventOrderPlacement unit tests.
 *
 * Pulls in Magento's composer autoloader so framework classes resolve, then adds
 * a PSR-4 autoloader for the module's own classes. Works whether the module is
 * installed under app/code/ or composer-installed under vendor/ — walks upward
 * looking for the nearest vendor/autoload.php instead of assuming a fixed depth.
 */
declare(strict_types=1);

$dir = __DIR__;
$autoload = null;
for ($i = 0; $i < 10; $i++) {
    $candidate = $dir . '/vendor/autoload.php';
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

if ($autoload === null) {
    fwrite(STDERR, "Could not locate vendor/autoload.php walking up from " . __DIR__ . "\n");
    exit(1);
}

require_once $autoload;

$moduleRoot = dirname(__DIR__, 2);
$moduleNamespace = 'DeployEcommerce\\PreventOrderPlacement\\';

spl_autoload_register(static function (string $class) use ($moduleRoot, $moduleNamespace): void {
    if (strncmp($class, $moduleNamespace, strlen($moduleNamespace)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($moduleNamespace));
    $path = $moduleRoot . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
