<?php

namespace GlpiPlugin\Reservationcoffee\Tests\Unit;

use GlpiPlugin\Reservationcoffee\Domain\PendingOffer;
use GlpiPlugin\Reservationcoffee\Service\PendingOfferStore;
use GlpiPlugin\Reservationcoffee\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class PendingOfferStoreTest extends TestCase
{
    private const USER_A = 7;
    private const USER_B = 8;
    private const TTL    = 300;

    private FrozenClock $clock;

    /** @var array<mixed> */
    private array $session = [];

    private PendingOfferStore $store;

    protected function setUp(): void
    {
        $this->clock   = FrozenClock::at('2026-10-09T15:00:00+00:00');
        $this->session = [];
        $this->store   = new PendingOfferStore($this->session, $this->clock);
    }

    private function offer(int $reservationId, int $usersId = self::USER_A, string $seriesKey = '', bool $eligible = true): PendingOffer
    {
        $now = $this->clock->now()->getTimestamp();

        return new PendingOffer($reservationId, $usersId, $eligible, $seriesKey, $now, $now + self::TTL);
    }

    public function testAddedOfferIsDeliverableToItsOwnerOnly(): void
    {
        self::assertTrue($this->store->add($this->offer(10)));

        self::assertSame(10, $this->store->latestDeliverable(self::USER_A)?->reservationId);
        self::assertNull($this->store->latestDeliverable(self::USER_B));
        self::assertNull($this->store->find(10, self::USER_B));
    }

    public function testStateIsWrittenToTheProvidedStorage(): void
    {
        $this->store->add($this->offer(10));

        self::assertArrayHasKey(10, $this->session);
        self::assertSame(self::USER_A, $this->session[10]['users_id']);
    }

    public function testLatestDeliverableReturnsTheMostRecentOffer(): void
    {
        $this->store->add($this->offer(10));
        $this->clock->advance(5);
        $this->store->add($this->offer(11));

        self::assertSame(11, $this->store->latestDeliverable(self::USER_A)?->reservationId);
    }

    public function testDispatchedOfferIsNotDeliveredAgainButCanStillBeFound(): void
    {
        $this->store->add($this->offer(10));
        $this->store->markDispatched(10, self::USER_A);

        self::assertNull($this->store->latestDeliverable(self::USER_A));
        self::assertTrue($this->store->find(10, self::USER_A)?->dispatched);
    }

    public function testExpiredOfferIsIgnoredAndPurged(): void
    {
        $this->store->add($this->offer(10));
        $this->clock->advance(self::TTL - 1);
        self::assertNotNull($this->store->find(10, self::USER_A));

        $this->clock->advance(1);
        self::assertNull($this->store->find(10, self::USER_A));
        self::assertNull($this->store->latestDeliverable(self::USER_A));

        $this->store->purgeExpired();
        self::assertSame([], $this->session);
    }

    public function testConsumeIsSingleUseAndOnlyAffectsTheGivenReservation(): void
    {
        $this->store->add($this->offer(10));
        $this->store->add($this->offer(11));

        self::assertSame(10, $this->store->consume(10, self::USER_A)?->reservationId);
        self::assertNull($this->store->consume(10, self::USER_A));
        self::assertNotNull($this->store->find(11, self::USER_A));
    }

    public function testConsumeByAnotherUserDoesNothing(): void
    {
        $this->store->add($this->offer(10));

        self::assertNull($this->store->consume(10, self::USER_B));
        self::assertNotNull($this->store->find(10, self::USER_A));
    }

    public function testRecurringSeriesProducesASingleOffer(): void
    {
        self::assertTrue($this->store->add($this->offer(10, self::USER_A, '3:12345')));
        self::assertFalse($this->store->add($this->offer(11, self::USER_A, '3:12345')));
        self::assertTrue($this->store->add($this->offer(12, self::USER_A, '4:12345')));

        self::assertNotNull($this->store->find(10, self::USER_A));
        self::assertNull($this->store->find(11, self::USER_A));
    }

    public function testMalformedSessionEntriesAreIgnored(): void
    {
        $this->session[99] = ['reservation_id' => '99', 'users_id' => self::USER_A];
        $this->session[98] = 'garbage';

        self::assertNull($this->store->find(99, self::USER_A));
        self::assertNull($this->store->latestDeliverable(self::USER_A));

        $this->store->purgeExpired();
        self::assertSame([], $this->session);
    }

    public function testStorageIsBounded(): void
    {
        for ($id = 1; $id <= 25; $id++) {
            $this->clock->advance(1);
            $this->store->add($this->offer($id));
        }

        self::assertCount(20, $this->session);
        self::assertNull($this->store->find(1, self::USER_A));
        self::assertNotNull($this->store->find(25, self::USER_A));
    }
}
