<?php
/**
 * Minimal PSR-4 autoloader.
 *
 * The plugin's only runtime dependency is the PHP AI Client SDK, which WordPress core or the AI
 * plugin already provides, so the release archive intentionally ships without a vendor directory.
 *
 * @package CommandCode\AiProvider
 */

declare(strict_types=1);

/*
 * WordPress loads this file through the plugin. A direct request has no business registering
 * autoloaders, so bail out. `exit` rather than `return` because that is the form the plugin review
 * tooling recognises as direct-access protection.
 */
if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'CommandCode\\AiProvider\\';
    $length = strlen($prefix);

    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, $length)) . '.php';
    if (file_exists($file)) {
        // Every class in this plugin is a plain, side-effect free definition.
        require $file;
    }
});
