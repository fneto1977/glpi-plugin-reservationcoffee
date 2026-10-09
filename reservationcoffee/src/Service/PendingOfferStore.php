<?php

/**
 * -------------------------------------------------------------------------
 * Reservation Coffee Break plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Reservation Coffee Break.
 *
 * Reservation Coffee Break is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * Reservation Coffee Break is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Reservation Coffee Break. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Reservationcoffee\Service;

use GlpiPlugin\Reservationcoffee\Domain\Clock;
use GlpiPlugin\Reservationcoffee\Domain\PendingOffer;

/**
 * Pending offers indexed by reservation ID, so that each browser tab can only
 * consume the offer of its own reservation.
 */
final class PendingOfferStore
{
    public const SESSION_KEY = 'reservationcoffee_pending';

    private const MAX_ENTRIES = 20;

    /** @var array<mixed> */
    private array $storage;

    /**
     * @param array<mixed> $storage
     */
    public function __construct(array &$storage, private readonly Clock $clock)
    {
        $this->storage = &$storage;
    }

    public static function fromSession(Clock $clock): self
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        return new self($_SESSION[self::SESSION_KEY], $clock);
    }

    /**
     * Returns false when another occurrence of the same recurring series is
     * already pending, so that a series produces a single offer.
     */
    public function add(PendingOffer $offer): bool
    {
        $this->purgeExpired();

        if ($offer->seriesKey !== '') {
            foreach ($this->all() as $existing) {
                if ($existing->usersId === $offer->usersId && $existing->seriesKey === $offer->seriesKey) {
                    return false;
                }
            }
        }

        $this->storage[$offer->reservationId] = $offer->toArray();
        $this->enforceCapacity();

        return true;
    }

    public function latestDeliverable(int $usersId): ?PendingOffer
    {
        $latest = null;
        foreach ($this->all() as $offer) {
            if ($offer->usersId !== $usersId || $offer->dispatched) {
                continue;
            }
            if (
                $latest === null
                || $offer->createdAt > $latest->createdAt
                || ($offer->createdAt === $latest->createdAt && $offer->reservationId > $latest->reservationId)
            ) {
                $latest = $offer;
            }
        }

        return $latest;
    }

    public function find(int $reservationId, int $usersId): ?PendingOffer
    {
        $offer = PendingOffer::fromArray($this->storage[$reservationId] ?? null);
        if ($offer === null || $offer->usersId !== $usersId || $offer->isExpired($this->now())) {
            return null;
        }

        return $offer;
    }

    public function markDispatched(int $reservationId, int $usersId): void
    {
        $offer = $this->find($reservationId, $usersId);
        if ($offer !== null) {
            $this->storage[$reservationId] = $offer->withDispatched()->toArray();
        }
    }

    public function consume(int $reservationId, int $usersId): ?PendingOffer
    {
        $offer = $this->find($reservationId, $usersId);
        if ($offer !== null) {
            unset($this->storage[$reservationId]);
        }

        return $offer;
    }

    public function purgeExpired(): void
    {
        $now = $this->now();
        foreach (array_keys($this->storage) as $key) {
            $offer = PendingOffer::fromArray($this->storage[$key]);
            if ($offer === null || $offer->isExpired($now)) {
                unset($this->storage[$key]);
            }
        }
    }

    /**
     * @return list<PendingOffer>
     */
    private function all(): array
    {
        $now = $this->now();
        $offers = [];
        foreach ($this->storage as $data) {
            $offer = PendingOffer::fromArray($data);
            if ($offer !== null && !$offer->isExpired($now)) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    private function enforceCapacity(): void
    {
        while (count($this->storage) > self::MAX_ENTRIES) {
            $oldestKey = null;
            $oldestCreatedAt = PHP_INT_MAX;
            foreach ($this->storage as $key => $data) {
                $createdAt = is_array($data) && is_int($data['created_at'] ?? null) ? $data['created_at'] : 0;
                if ($createdAt < $oldestCreatedAt) {
                    $oldestCreatedAt = $createdAt;
                    $oldestKey = $key;
                }
            }
            unset($this->storage[$oldestKey]);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
