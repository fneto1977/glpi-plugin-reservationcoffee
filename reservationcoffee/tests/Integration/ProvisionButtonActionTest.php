<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Controller\ConfigController;
use Session;

final class ProvisionButtonActionTest extends IntegrationTestCase
{
    public function testButtonExecutesProvisionerAndAutoConfigures(): void
    {
        $this->loginAs('glpi');

        $controller = new ConfigController();
        $token = Session::getNewCSRFToken();
        $_POST['_glpi_csrf_token'] = $token;
        $_POST['provision_form'] = '1';

        $controller->provision(false);

        $config = Config::load();
        self::assertSame($this->formId(), $config->coffeeFormId);
        self::assertSame($this->reservationQuestionId(), $config->reservationQuestionId);
    }
}
