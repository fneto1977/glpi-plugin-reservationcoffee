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

use DateTimeZone;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\CoffeeEligibility;
use GlpiPlugin\Reservationcoffee\Domain\ReservationContext;
use Reservation;
use ReservationItem;
use Session;

/**
 * Resolves reservation -> reservable item -> asset, always from the database.
 */
final class ReservationResolver
{
    public function __construct(private readonly Config $config) {}

    public function load(int $reservationId): ?ReservationContext
    {
        if ($reservationId <= 0) {
            return null;
        }

        $reservation = Reservation::getById($reservationId);
        if (!$reservation instanceof Reservation) {
            return null;
        }

        return $this->fromReservation($reservation);
    }

    /**
     * Returns null when the reservation does not target a configured room.
     */
    public function fromReservation(Reservation $reservation): ?ReservationContext
    {
        $reservationItemId = (int) ($reservation->fields['reservationitems_id'] ?? 0);
        if (!$this->config->isReservableItemConfigured($reservationItemId)) {
            return null;
        }

        $reservationItem = ReservationItem::getById($reservationItemId);
        if (!$reservationItem instanceof ReservationItem) {
            return null;
        }

        $asset = $reservationItem->getItem();
        $roomName = $asset !== false ? (string) $asset->getName() : '';

        $rawBegin = (string) ($reservation->fields['begin'] ?? '');
        $rawEnd   = (string) ($reservation->fields['end'] ?? '');

        return new ReservationContext(
            (int) $reservation->getID(),
            $reservationItemId,
            (int) ($reservation->fields['users_id'] ?? 0),
            (int) ($reservation->fields['group'] ?? 0),
            $roomName,
            $rawBegin,
            $rawEnd,
            CoffeeEligibility::parseGlpiDateTime($rawBegin, new DateTimeZone(date_default_timezone_get())),
        );
    }

    /**
     * The reservation owner, or a user allowed to see it and to manage
     * reservations of others, may request a coffee break for it.
     */
    public function canRequestCoffee(int $reservationId, int $usersId): bool
    {
        $reservation = Reservation::getById($reservationId);
        if (!$reservation instanceof Reservation || $usersId <= 0) {
            return false;
        }

        if ((int) $reservation->fields['users_id'] === $usersId) {
            return true;
        }

        return $reservation->canViewItem() && Session::haveRight(Reservation::$rightname, UPDATE);
    }
}
