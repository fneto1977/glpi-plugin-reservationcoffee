<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\CoffeeEligibility;
use GlpiPlugin\Reservationcoffee\Domain\SystemClock;
use GlpiPlugin\Reservationcoffee\Service\PendingOfferStore;
use GlpiPlugin\Reservationcoffee\Service\PluginLogger;
use GlpiPlugin\Reservationcoffee\Service\ReservationListener;
use GlpiPlugin\Reservationcoffee\Service\ReservationResolver;
use Reservation;

final class ReservationListenerTest extends IntegrationTestCase
{
    /** @var array<int|string, mixed> */
    private array $storage = [];

    private function listener(bool $interactive, ?Config $config = null): ReservationListener
    {
        $config ??= Config::load();
        $clock = new SystemClock();

        return new ReservationListener(
            $config,
            new ReservationResolver($config),
            new CoffeeEligibility($clock, Config::MIN_NOTICE_SECONDS),
            $clock,
            new PluginLogger(),
            static fn(): bool => $interactive,
            fn(): PendingOfferStore => new PendingOfferStore($this->storage, $clock),
        );
    }

    private function store(): PendingOfferStore
    {
        return new PendingOfferStore($this->storage, new SystemClock());
    }

    public function testRealHookInCliKeepsTheReservationAndRecordsNoOffer(): void
    {
        unset($_SESSION[PendingOfferStore::SESSION_KEY]);
        $owner = $this->loginAs('e2e.selfservice');

        // CommonDBTM::add() fires the plugin `item_add` hook for real here.
        $reservation = $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400));

        self::assertTrue(Reservation::getById((int) $reservation->getID()) instanceof Reservation);
        self::assertSame([], $_SESSION[PendingOfferStore::SESSION_KEY] ?? []);
    }

    public function testInteractiveReservationOfARoomRecordsAnEligibleOffer(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservation = $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400));

        $this->listener(true)->afterAdd($reservation);

        $offer = $this->store()->latestDeliverable($owner);
        self::assertNotNull($offer);
        self::assertSame((int) $reservation->getID(), $offer->reservationId);
        self::assertTrue($offer->eligible);
        self::assertNull($this->store()->latestDeliverable($this->userId('e2e.selfservice2')));
    }

    public function testReservationBelow48HoursRecordsANonEligibleOffer(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservation = $this->createReservation(self::ROOM, $owner, $this->startIn(86400));

        $this->listener(true)->afterAdd($reservation);

        $offer = $this->store()->latestDeliverable($owner);
        self::assertNotNull($offer);
        self::assertFalse($offer->eligible);
    }

    public function testNonRoomNonInteractiveAndDisabledConfigurationsRecordNothing(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $nonRoom = $this->createReservation(self::NON_ROOM, $owner, $this->startIn(3 * 86400));
        $room = $this->createReservation(self::OTHER_ROOM, $owner, $this->startIn(3 * 86400));

        $this->listener(true)->afterAdd($nonRoom);
        $this->listener(false)->afterAdd($room);
        $this->listener(true, Config::fromRaw([]))->afterAdd($room);

        self::assertSame([], $this->storage);
    }

    public function testFailuresNeverEscapeTheHook(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservation = $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400));
        $reservation->fields['begin'] = 'not a date';

        $this->listener(true)->afterAdd($reservation);

        self::assertSame([], $this->storage);
    }
}
