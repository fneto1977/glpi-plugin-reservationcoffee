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

namespace GlpiPlugin\Reservationcoffee\Domain;

/**
 * Short-lived post-reservation state kept in the user session.
 */
final class PendingOffer
{
    public function __construct(
        public readonly int $reservationId,
        public readonly int $usersId,
        public readonly bool $eligible,
        public readonly string $seriesKey,
        public readonly int $createdAt,
        public readonly int $expiresAt,
        public readonly bool $dispatched = false,
    ) {}

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function withDispatched(): self
    {
        return new self(
            $this->reservationId,
            $this->usersId,
            $this->eligible,
            $this->seriesKey,
            $this->createdAt,
            $this->expiresAt,
            true,
        );
    }

    /**
     * @return array{reservation_id: int, users_id: int, eligible: bool, series_key: string, created_at: int, expires_at: int, dispatched: bool}
     */
    public function toArray(): array
    {
        return [
            'reservation_id' => $this->reservationId,
            'users_id'       => $this->usersId,
            'eligible'       => $this->eligible,
            'series_key'     => $this->seriesKey,
            'created_at'     => $this->createdAt,
            'expires_at'     => $this->expiresAt,
            'dispatched'     => $this->dispatched,
        ];
    }

    /**
     * Returns null for malformed session data instead of trusting it.
     *
     * @param mixed $data
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        foreach (['reservation_id', 'users_id', 'created_at', 'expires_at'] as $key) {
            if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] <= 0) {
                return null;
            }
        }
        if (!isset($data['eligible']) || !is_bool($data['eligible'])) {
            return null;
        }
        if (!isset($data['series_key']) || !is_string($data['series_key'])) {
            return null;
        }

        return new self(
            $data['reservation_id'],
            $data['users_id'],
            $data['eligible'],
            $data['series_key'],
            $data['created_at'],
            $data['expires_at'],
            ($data['dispatched'] ?? false) === true,
        );
    }
}
