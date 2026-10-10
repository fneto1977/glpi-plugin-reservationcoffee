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

use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Service\CoffeeRequestGuard;
use GlpiPlugin\Reservationcoffee\Service\CoffeeTicketDetails;
use GlpiPlugin\Reservationcoffee\Service\ReservationListener;

function plugin_reservationcoffee_install(): bool
{
    Config::installDefaults();

    return true;
}

function plugin_reservationcoffee_uninstall(): bool
{
    // The plugin only owns its configuration entries; reservations, forms and
    // tickets belong to GLPI and are never touched.
    Config::removeAll();

    return true;
}

function plugin_reservationcoffee_item_add_reservation(CommonDBTM $item): void
{
    ReservationListener::create()->afterAdd($item);
}

function plugin_reservationcoffee_pre_item_add_answers_set(CommonDBTM $item): void
{
    CoffeeRequestGuard::create()->beforeAdd($item);
}

function plugin_reservationcoffee_pre_item_add_ticket(CommonDBTM $item): void
{
    CoffeeTicketDetails::create()->beforeAdd($item);
}
