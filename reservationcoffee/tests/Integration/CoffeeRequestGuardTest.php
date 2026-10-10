<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use Glpi\Form\AnswersSet;
use GlpiPlugin\Reservationcoffee\Service\CoffeeRequestGuard;

final class CoffeeRequestGuardTest extends IntegrationTestCase
{
    /**
     * Builds the input `AnswersHandler` passes to `AnswersSet::add()`.
     */
    private function submission(int $usersId, mixed $reservationAnswer, ?int $formId = null, mixed $participants = '8'): AnswersSet
    {
        $answersSet = new AnswersSet();
        $answersSet->input = [
            'forms_forms_id' => $formId ?? $this->formId(),
            'users_id'       => $usersId,
            'answers'        => json_encode([
                ['question_id' => $this->reservationQuestionId(), 'raw_answer' => $reservationAnswer],
                ['question_id' => $this->questionId('Participantes'), 'raw_answer' => $participants],
            ]),
        ];

        return $answersSet;
    }

    private function isAccepted(AnswersSet $answersSet): bool
    {
        CoffeeRequestGuard::create()->beforeAdd($answersSet);

        return $answersSet->input !== false;
    }

    public function testOwnReservationWithEnoughNoticeIsAccepted(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400))->getID();

        self::assertTrue($this->isAccepted($this->submission($owner, $reservationId)));
        self::assertTrue($this->isAccepted($this->submission($owner, (string) $reservationId)));
    }

    public function testReservationBelow48HoursIsRejected(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(86400))->getID();

        self::assertFalse($this->isAccepted($this->submission($owner, $reservationId)));
    }

    public function testAnotherUsersReservationIsRejected(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400))->getID();

        $intruder = $this->loginAs('e2e.selfservice2');
        self::assertFalse($this->isAccepted($this->submission($intruder, $reservationId)));
    }

    public function testNonRoomUnknownAndMalformedAnswersAreRejected(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $nonRoomId = (int) $this->createReservation(self::NON_ROOM, $owner, $this->startIn(3 * 86400))->getID();

        foreach ([$nonRoomId, 999999999, 0, -5, '12abc', '', null, [1]] as $answer) {
            self::assertFalse($this->isAccepted($this->submission($owner, $answer)), var_export($answer, true));
        }
        self::assertFalse($this->isAccepted($this->submission(0, $nonRoomId)));
    }

    public function testParticipantsMustBeAPositiveInteger(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400))->getID();

        self::assertTrue($this->isAccepted($this->submission($owner, $reservationId, null, 1)));
        foreach (['0', 0, '-2', '2.5', 'doze', '', null] as $participants) {
            self::assertFalse(
                $this->isAccepted($this->submission($owner, $reservationId, null, $participants)),
                var_export($participants, true),
            );
        }
    }

    public function testOtherFormsAreNotGuarded(): void
    {
        $owner = $this->loginAs('e2e.selfservice');

        self::assertTrue($this->isAccepted($this->submission($owner, 'anything', $this->formId() + 100000)));
    }
}
