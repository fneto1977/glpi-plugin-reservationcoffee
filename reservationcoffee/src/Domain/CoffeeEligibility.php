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
use DateTimeZone;
use InvalidArgumentException;

/**
 * "48 hours" means 172800 elapsed seconds, not two business days, so the
 * comparison is done on Unix timestamps and is immune to DST shifts.
 */
final class CoffeeEligibility
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $minNoticeSeconds,
    ) {
        if ($minNoticeSeconds <= 0) {
            throw new InvalidArgumentException('Minimum notice must be a positive number of seconds.');
        }
    }

    public function isEligible(DateTimeImmutable $start): bool
    {
        return $start->getTimestamp() >= $this->clock->now()->getTimestamp() + $this->minNoticeSeconds;
    }

    /**
     * GLPI reads `timestamp` columns in the timezone shared by PHP and the DB
     * session (server default, or the user timezone when timezones are
     * enabled), so the raw value must be interpreted in that same timezone.
     */
    public static function parseGlpiDateTime(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        if ($date === false) {
            throw new InvalidArgumentException('Invalid GLPI datetime value.');
        }

        return $date;
    }
}
