<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use Exception;
use Glpi\Form\AnswersHandler\AnswersHandler;
use Glpi\Form\AnswersSet;
use Glpi\Form\Form;
use Glpi\Form\Question;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\ReservationContext;
use GlpiPlugin\Reservationcoffee\Service\CoffeeFormLink;
use GlpiPlugin\Reservationcoffee\Service\CoffeeRequestGuard;
use GlpiPlugin\Reservationcoffee\Service\CoffeeTicketDetails;
use GlpiPlugin\Reservationcoffee\Service\ReservationResolver;
use Html;
use Ticket;

/**
 * Coffee break form as seeded by tools/seed_e2e.php: pre-filled link, native
 * validation and visibility, and the ticket created on submission.
 */
final class CoffeeFormTest extends IntegrationTestCase
{
    private int $lastTicketId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        global $DB;
        $this->lastTicketId = (int) $DB->request(['SELECT' => ['MAX' => 'id AS id'], 'FROM' => Ticket::getTable()])->current()['id'];
        CoffeeTicketDetails::forget();
    }

    protected function tearDown(): void
    {
        global $DB;
        CoffeeTicketDetails::forget();
        $this->loginAs('glpi');
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Ticket::getTable(), 'WHERE' => ['id' => ['>', $this->lastTicketId]]]) as $row) {
            (new Ticket())->delete(['id' => $row['id']], true);
        }
        foreach ((new AnswersSet())->find(['forms_forms_id' => $this->formId()]) as $row) {
            (new AnswersSet())->delete(['id' => $row['id']], true);
        }
        parent::tearDown();
    }

    private function context(int $reservationId): ReservationContext
    {
        $context = (new ReservationResolver(Config::load()))->load($reservationId);
        self::assertNotNull($context);

        return $context;
    }

    /**
     * @param array<string, mixed> $overrides answer per question name
     *
     * @return array<int, mixed>
     */
    private function answers(int $reservationId, array $overrides = []): array
    {
        $byName = $overrides + [
            'ID da reserva'             => (string) $reservationId,
            'Solicitante'               => 'Pessoa E2E',
            'Data'                      => '01-01-2030',
            'Horário de início'         => '09:00',
            'Sala'                      => 'Sala digitada',
            'Participantes'             => '12',
            'Necessita de Coffee Break' => 'sim',
            'Itens solicitados'         => ['cafe', 'agua'],
            'Observações'               => 'Sem açúcar',
        ];

        $answers = [];
        foreach ($byName as $name => $answer) {
            if ($answer !== null) {
                $answers[$this->questionId($name)] = $answer;
            }
        }

        return $answers;
    }

    /**
     * @param array<int, mixed> $answers
     *
     * @return list<string> validation errors, as GLPI shows them
     */
    private function validationErrors(array $answers): array
    {
        $form = Form::getById($this->formId());
        $answers = AnswersHandler::getInstance()->removeUnusedAnswers($form, $answers);
        $result = AnswersHandler::getInstance()->validateAnswers($form, $answers);

        return array_values(array_map(static fn(array $error): string => (string) $error['message'], $result->getErrors()));
    }

    /**
     * @param array<int, mixed> $answers
     */
    private function submit(int $usersId, array $answers): ?Ticket
    {
        $form = Form::getById($this->formId());
        $answers = AnswersHandler::getInstance()->removeUnusedAnswers($form, $answers);
        $answersSet = AnswersHandler::getInstance()->saveAnswers($form, $answers, $usersId);

        $tickets = array_values(array_filter($answersSet->getCreatedItems(), static fn($item) => $item instanceof Ticket));
        if ($tickets === []) {
            return null;
        }

        return Ticket::getById($tickets[0]->getID());
    }

    public function testLinkPrefillsReservationRequesterDateTimeAndRoom(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $begin = $this->startIn(3 * 86400);
        $context = $this->context((int) $this->createReservation(self::ROOM, $owner, $begin)->getID());

        $url = (new CoffeeFormLink(Config::load(), ''))->build($context, 'Pessoa E2E');

        self::assertNotNull($url);
        self::assertStringStartsWith('/Form/Render/' . $this->formId() . '?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expected = [
            'ID da reserva'     => (string) $context->reservationId,
            'Solicitante'       => 'Pessoa E2E',
            'Data'              => Html::convDate($begin->format('Y-m-d')),
            'Horário de início' => $begin->format('H:i'),
            'Sala'              => self::ROOM,
        ];
        $actual = [];
        foreach ($expected as $name => $value) {
            $uuid = (string) Question::getById($this->questionId($name))->fields['uuid'];
            $actual[$name] = $query[str_replace('.', '_', $uuid)] ?? null;
        }
        self::assertSame($expected, $actual);
    }

    public function testLinkSkipsQuestionsOfAnotherForm(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $context = $this->context((int) $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400))->getID());
        $config = Config::load();
        $foreign = new Config(
            $config->reservableItemIds,
            $config->coffeeFormId,
            $config->reservationQuestionId,
            $config->pendingTtlSeconds,
            [],
            [Config::KEY_ROOM_QUESTION_ID => 999999999],
        );

        $url = (string) (new CoffeeFormLink($foreign, ''))->build($context, 'Pessoa E2E');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertCount(1, $query);
        self::assertContains((string) $context->reservationId, $query);
    }

    public function testParticipantsMustBeAPositiveInteger(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400))->getID();

        foreach (['1', '12', '9999'] as $valid) {
            self::assertSame([], $this->validationErrors($this->answers($reservationId, ['Participantes' => $valid])), $valid);
        }
        foreach (['-3', '2.5', '1e3', '10000', 'doze'] as $invalid) {
            self::assertNotSame([], $this->validationErrors($this->answers($reservationId, ['Participantes' => $invalid])), $invalid);
        }
        self::assertNotSame([], $this->validationErrors($this->answers($reservationId, ['Participantes' => null])));
    }

    public function testZeroParticipantsIsRejectedOnSubmission(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400))->getID();
        $answers = $this->answers($reservationId, ['Participantes' => '0']);

        // GLPI does not run the validation conditions on "0", which it considers an empty answer.
        self::assertSame([], $this->validationErrors($answers));
        $this->assertRejected($owner, $answers);
    }

    public function testItemsAreOnlyAskedWhenCoffeeBreakIsNeeded(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400))->getID();
        $form = Form::getById($this->formId());
        $itemsId = $this->questionId('Itens solicitados');

        self::assertNotSame([], $this->validationErrors($this->answers($reservationId, ['Itens solicitados' => null])));

        $declined = $this->answers($reservationId, ['Necessita de Coffee Break' => 'nao']);
        self::assertSame([], $this->validationErrors($declined));
        self::assertArrayNotHasKey($itemsId, AnswersHandler::getInstance()->removeUnusedAnswers($form, $declined));
    }

    public function testTicketHasTheReservationAsBookedAndTheAnswers(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $begin = $this->startIn(4 * 86400);
        $context = $this->context((int) $this->createReservation(self::ROOM, $owner, $begin)->getID());

        $ticket = $this->submit($owner, $this->answers($context->reservationId));

        self::assertSame(CoffeeTicketDetails::title($context), $ticket->fields['name']);
        self::assertStringContainsString(self::ROOM, $ticket->fields['name']);
        self::assertStringContainsString($begin->format('d/m/Y H:i'), $ticket->fields['name']);
        self::assertStringEndsWith('#' . $context->reservationId, $ticket->fields['name']);
        self::assertStringNotContainsString('Sala digitada', $ticket->fields['name']);

        $content = (string) $ticket->fields['content'];
        self::assertStringContainsString(htmlescape(self::ROOM), $content);
        self::assertStringContainsString($begin->modify('+1 hour')->format('d/m/Y H:i'), $content);
        foreach (['Participantes', '12', 'Café, Água', 'Sala digitada', 'Sem açúcar'] as $expected) {
            self::assertStringContainsString(htmlescape($expected), $content, $expected);
        }
        self::assertSame(CoffeeTicketDetails::summary($context), substr($content, 0, strlen(CoffeeTicketDetails::summary($context))));
    }

    public function testDeclinedCoffeeBreakCreatesNoTicket(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $context = $this->context((int) $this->createReservation(self::ROOM, $owner, $this->startIn(4 * 86400))->getID());

        $ticket = $this->submit($owner, $this->answers($context->reservationId, ['Necessita de Coffee Break' => 'nao']));

        self::assertNull($ticket);
    }

    public function testShortNoticeIsRejectedByTheReservationIdWhateverTheEditedDate(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(86400))->getID();

        $this->assertRejected($owner, $this->answers($reservationId, ['Data' => '01-01-2030']));
    }

    /**
     * @param array<int, mixed> $answers
     */
    private function assertRejected(int $usersId, array $answers): void
    {
        global $DB;

        $rejected = false;
        try {
            $this->submit($usersId, $answers);
        } catch (Exception) {
            $rejected = true;
        }

        self::assertTrue($rejected, 'The submission should have been rejected');
        self::assertCount(0, $DB->request(['FROM' => Ticket::getTable(), 'WHERE' => ['id' => ['>', $this->lastTicketId]]]));
    }

    public function testOtherTicketsKeepTheirTitle(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $context = $this->context((int) $this->createReservation(self::ROOM, $owner, $this->startIn(4 * 86400))->getID());
        $this->submit($owner, $this->answers($context->reservationId));

        // A later answers set of any form ends the coffee break request.
        $other = new AnswersSet();
        $other->input = ['forms_forms_id' => $this->formId() + 100000, 'users_id' => $owner, 'answers' => '[]'];
        CoffeeRequestGuard::create()->beforeAdd($other);

        $ticket = new Ticket();
        $id = $ticket->add(['name' => 'Chamado comum E2E', 'content' => 'Conteúdo', 'entities_id' => 0]);

        self::assertNotFalse($id);
        self::assertSame('Chamado comum E2E', Ticket::getById($id)->fields['name']);
    }
}
