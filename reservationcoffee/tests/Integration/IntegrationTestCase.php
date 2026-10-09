<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use Auth;
use DateTimeImmutable;
use DateTimeZone;
use Glpi\Form\Form;
use Glpi\Form\Question;
use PHPUnit\Framework\TestCase;
use Reservation;
use ReservationItem;
use RuntimeException;
use Session;
use User;

/**
 * Base class for tests running against the seeded E2E database. Every
 * reservation created by a test is purged afterwards.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected const ROOM = 'Sala de Reunião 01';
    protected const OTHER_ROOM = 'Sala de Reunião 02';
    protected const NON_ROOM = 'Projetor Portátil E2E';
    protected const FORM = 'Solicitação de Coffee Break';
    protected const RESERVATION_QUESTION = 'ID da reserva';

    /** @var list<int> */
    private array $createdReservations = [];

    protected function tearDown(): void
    {
        $this->loginAs('glpi');
        foreach ($this->createdReservations as $id) {
            (new Reservation())->delete(['id' => $id], true);
        }
        $this->createdReservations = [];
        parent::tearDown();
    }

    protected function loginAs(string $login): int
    {
        $auth = new Auth();
        $auth->user = new User();
        if (!$auth->user->getFromDBbyName($login)) {
            throw new RuntimeException(sprintf('Seed user "%s" not found; run tools/seed-e2e.sh', $login));
        }
        $auth->auth_succeded = true;
        Session::init($auth);

        return (int) Session::getLoginUserID();
    }

    protected function userId(string $login): int
    {
        $user = new User();
        if (!$user->getFromDBbyName($login)) {
            throw new RuntimeException(sprintf('Seed user "%s" not found', $login));
        }

        return (int) $user->getID();
    }

    protected function reservationItemId(string $assetName): int
    {
        global $DB;

        foreach ($DB->request(['FROM' => ReservationItem::getTable()]) as $row) {
            $item = getItemForItemtype($row['itemtype']);
            if ($item !== false && $item->getFromDB($row['items_id']) && $item->getName() === $assetName) {
                return (int) $row['id'];
            }
        }

        throw new RuntimeException(sprintf('Reservable item "%s" not found', $assetName));
    }

    protected function formId(): int
    {
        $form = new Form();
        if (!$form->getFromDBByCrit(['name' => self::FORM])) {
            throw new RuntimeException('Seed form not found');
        }

        return (int) $form->getID();
    }

    protected function reservationQuestionId(): int
    {
        $question = new Question();
        if (!$question->getFromDBByCrit(['name' => self::RESERVATION_QUESTION])) {
            throw new RuntimeException('Seed question not found');
        }

        return (int) $question->getID();
    }

    /**
     * Starts on a whole minute, `$offsetSeconds` from now, in the PHP default timezone used by GLPI.
     */
    protected function startIn(int $offsetSeconds): DateTimeImmutable
    {
        $timestamp = intdiv(time() + $offsetSeconds + 59, 60) * 60;

        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    protected function createReservation(string $assetName, int $usersId, DateTimeImmutable $begin): Reservation
    {
        $reservation = new Reservation();
        $id = $reservation->add([
            'reservationitems_id' => $this->reservationItemId($assetName),
            'users_id'            => $usersId,
            'begin'               => $begin->format('Y-m-d H:i:s'),
            'end'                 => $begin->modify('+1 hour')->format('Y-m-d H:i:s'),
            'comment'             => '',
        ]);
        if (!$id) {
            throw new RuntimeException('Unable to create the reservation');
        }
        $this->createdReservations[] = (int) $id;

        return $reservation;
    }
}
