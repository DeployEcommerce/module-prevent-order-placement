<?php
/**
 * Copyright © DeployEcommerce. All rights reserved.
 *
 * PHPUnit/Pest bootstrap for DeployEcommerce_PreventOrderPlacement unit tests.
 *
 * Pulls in Magento's composer autoloader so framework classes resolve, then adds
 * a PSR-4 autoloader for the module's own classes (which live in app/code/ and
 * aren't reachable through composer's autoload without Magento's full bootstrap).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 6) . '/vendor/autoload.php';

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
