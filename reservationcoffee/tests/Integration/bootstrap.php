<?php

/**
 * Boots a real GLPI instance for the integration suite. Runs inside the GLPI
 * container of docker/compose.e2e.yml (see tools/integration-test.sh), against
 * the synthetic E2E database. Never point it at a production database.
 */

declare(strict_types=1);

$glpiRoot = getenv('GLPI_ROOT_DIR') ?: '/var/www/glpi';
chdir($glpiRoot);
require $glpiRoot . '/vendor/autoload.php';

// Booting the console application initialises the database, configuration and active plugins.
new Glpi\Console\Application(new Glpi\Kernel\Kernel('production'));

if (!Plugin::isPluginActive('reservationcoffee')) {
    fwrite(STDERR, "The reservationcoffee plugin must be installed and active (tools/e2e-up.sh).\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Reservationcoffee\\Tests\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
