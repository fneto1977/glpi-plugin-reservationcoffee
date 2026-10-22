<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use GlpiPlugin\Reservationcoffee\Config;

require_once __DIR__ . '/../../hook.php';

final class InstallHookTest extends IntegrationTestCase
{
    public function testInstallHookProvisionsFormAutomatically(): void
    {
        $result = plugin_reservationcoffee_install();

        self::assertTrue($result);
        $config = Config::load();
        self::assertGreaterThan(0, $config->coffeeFormId);
        self::assertGreaterThan(0, $config->reservationQuestionId);
    }
}
