<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Reservationcoffee\Controller\ConfigController;
use Session;

final class AjaxFormQuestionsTest extends IntegrationTestCase
{
    public function testReturnsQuestionsForValidForm(): void
    {
        $this->loginAs('glpi');

        $controller = new ConfigController();
        $questions = $controller->getQuestionsForForm($this->formId());

        self::assertNotEmpty($questions);
        $names = array_column($questions, 'name');
        self::assertContains('ID da reserva', $names);
        self::assertContains('Participantes', $names);
        self::assertContains('Necessita de Coffee Break', $names);
        self::assertContains('Itens solicitados', $names);
    }

    public function testRejectsUnauthorizedAjaxRequest(): void
    {
        $this->loginAs('e2e.selfservice');

        $this->expectException(AccessDeniedHttpException::class);

        Session::checkRight('config', UPDATE);
    }
}
