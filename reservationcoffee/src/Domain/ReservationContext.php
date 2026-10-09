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

use DateTimeImmutable;

/**
 * Reservation of a configured meeting room, as reloaded from the database.
 */
final class ReservationContext
{
    public function __construct(
        public readonly int $reservationId,
        public readonly int $reservationItemId,
        public readonly int $usersId,
        public readonly int $group,
        public readonly string $roomName,
        public readonly string $rawBegin,
        public readonly string $rawEnd,
        public readonly DateTimeImmutable $begin,
    ) {}

    /**
     * Occurrences of a recurring reservation share the same `group` value per
     * reservation item; a non-recurring reservation has `group = 0`.
     */
    public function seriesKey(): string
    {
        return $this->group > 0 ? sprintf('%d:%d', $this->reservationItemId, $this->group) : '';
    }
}
