<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Controller\ConfigController;
use Session;

final class ConfigFormSubmitTest extends IntegrationTestCase
{
    public function testValidSubmissionPersistsConfig(): void
    {
        $this->loginAs('glpi');
        $originalConfig = Config::load();

        $formId = $this->formId();
        $resQuestionId = $this->reservationQuestionId();
        $roomId1 = $this->reservationItemId(self::ROOM);
        $roomId2 = $this->reservationItemId(self::OTHER_ROOM);

        $controller = new ConfigController();
        $token = Session::getNewCSRFToken();

        $post = [
            '_glpi_csrf_token'                   => $token,
            'save_config'                        => '1',
            Config::KEY_RESERVABLE_ITEM_IDS      => [(string) $roomId1, (string) $roomId2],
            Config::KEY_COFFEE_FORM_ID           => (string) $formId,
            Config::KEY_RESERVATION_QUESTION_ID  => (string) $resQuestionId,
            Config::KEY_PENDING_TTL_SECONDS      => '450',
            Config::KEY_PARTICIPANTS_QUESTION_ID => (string) $this->questionId('Participantes'),
            Config::KEY_REQUESTER_QUESTION_ID    => (string) $this->questionId('Solicitante'),
            Config::KEY_DATE_QUESTION_ID         => (string) $this->questionId('Data'),
            Config::KEY_TIME_QUESTION_ID         => (string) $this->questionId('Horário de início'),
            Config::KEY_ROOM_QUESTION_ID         => (string) $this->questionId('Sala'),
        ];

        try {
            $controller->save($post, false);

            $saved = Config::load();
            self::assertSame([$roomId1, $roomId2], $saved->reservableItemIds);
            self::assertSame($formId, $saved->coffeeFormId);
            self::assertSame($resQuestionId, $saved->reservationQuestionId);
            self::assertSame(450, $saved->pendingTtlSeconds);
            self::assertSame($this->questionId('Participantes'), $saved->participantsQuestionId);
            self::assertSame($this->questionId('Solicitante'), $saved->prefillQuestionIds[Config::KEY_REQUESTER_QUESTION_ID]);
            self::assertTrue($saved->isOfferEnabled());
            self::assertTrue($saved->isGuardEnabled());
        } finally {
            $this->restoreConfig($originalConfig);
        }
    }

    public function testMissingCsrfTokenFails(): void
    {
        $this->loginAs('glpi');

        $controller = new ConfigController();

        $this->expectException(AccessDeniedHttpException::class);

        $controller->save([
            'save_config' => '1',
            Config::KEY_RESERVABLE_ITEM_IDS => ['1'],
        ], false);
    }
}
