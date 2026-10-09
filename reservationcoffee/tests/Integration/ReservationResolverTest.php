<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Service\ReservationResolver;

final class ReservationResolverTest extends IntegrationTestCase
{
    private ReservationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ReservationResolver(Config::load());
    }

    public function testSeededConfigurationIsLoadedFromTheDatabase(): void
    {
        $config = Config::load();

        self::assertSame([], $config->invalidKeys);
        self::assertTrue($config->isOfferEnabled());
        self::assertTrue($config->isGuardEnabled());
        self::assertTrue($config->isReservableItemConfigured($this->reservationItemId(self::ROOM)));
        self::assertFalse($config->isReservableItemConfigured($this->reservationItemId(self::NON_ROOM)));
    }

    public function testConfiguredRoomIsResolvedFromTheDatabase(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $begin = $this->startIn(3 * 86400);
        $reservation = $this->createReservation(self::ROOM, $owner, $begin);

        $context = $this->resolver->load((int) $reservation->getID());

        self::assertNotNull($context);
        self::assertSame(self::ROOM, $context->roomName);
        self::assertSame($owner, $context->usersId);
        self::assertSame($this->reservationItemId(self::ROOM), $context->reservationItemId);
        self::assertSame($begin->getTimestamp(), $context->begin->getTimestamp());
        self::assertSame('', $context->seriesKey());
    }

    public function testNonRoomAndUnknownReservationsAreIgnored(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservation = $this->createReservation(self::NON_ROOM, $owner, $this->startIn(3 * 86400));

        self::assertNull($this->resolver->load((int) $reservation->getID()));
        self::assertNull($this->resolver->load(999999999));
        self::assertNull($this->resolver->load(0));
    }

    public function testOnlyTheOwnerOrAReservationManagerMayRequestCoffee(): void
    {
        $owner = $this->loginAs('e2e.selfservice');
        $reservationId = (int) $this->createReservation(self::ROOM, $owner, $this->startIn(3 * 86400))->getID();
        self::assertTrue($this->resolver->canRequestCoffee($reservationId, $owner));

        $other = $this->loginAs('e2e.selfservice2');
        self::assertFalse($this->resolver->canRequestCoffee($reservationId, $other));

        $outsider = $this->loginAs('e2e.otherentity');
        self::assertFalse($this->resolver->canRequestCoffee($reservationId, $outsider));

        $admin = $this->loginAs('glpi');
        self::assertTrue($this->resolver->canRequestCoffee($reservationId, $admin));

        self::assertFalse($this->resolver->canRequestCoffee($reservationId, 0));
        self::assertFalse($this->resolver->canRequestCoffee(999999999, $admin));
    }
}
