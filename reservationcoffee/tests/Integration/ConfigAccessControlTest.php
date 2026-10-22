<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Reservationcoffee\Controller\ConfigController;

final class ConfigAccessControlTest extends IntegrationTestCase
{
    public function testNonAdminReceivesForbidden(): void
    {
        $this->loginAs('e2e.selfservice');

        $this->expectException(AccessDeniedHttpException::class);

        $controller = new ConfigController();
        $controller->render(false);
    }

    public function testAdminCanViewConfigPage(): void
    {
        $this->loginAs('glpi');

        $controller = new ConfigController();
        $html = $controller->render(false);

        self::assertStringContainsString('Coffee Break', $html);
        self::assertStringContainsString('name="_glpi_csrf_token"', $html);
        self::assertStringContainsString('id="reservable_item_ids"', $html);
        self::assertStringContainsString('id="coffee_form_id"', $html);
        self::assertStringContainsString('id="reservation_question_id"', $html);
        self::assertStringContainsString('id="btn-provision-default-form"', $html);
        self::assertStringContainsString('id="btn-save-config"', $html);
    }
}
