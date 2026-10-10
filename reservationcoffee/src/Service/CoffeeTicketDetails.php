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

use CommonDBTM;
use DateTimeImmutable;
use GlpiPlugin\Reservationcoffee\Domain\ReservationContext;
use Throwable;
use Ticket;

/**
 * `pre_item_add` handler for tickets. The form questions pre-filled from the
 * reservation remain editable, so the ticket of an accepted coffee break
 * request gets the reservation as stored in the database: a standard title
 * and a summary above the answers rendered by the form destination.
 *
 * GLPI creates the destination tickets right after the answers set, in the
 * same request, so the reservation accepted by CoffeeRequestGuard is only kept
 * in memory for the current request.
 */
final class CoffeeTicketDetails
{
    private static ?ReservationContext $accepted = null;

    public function __construct(private readonly PluginLogger $logger) {}

    public static function create(): self
    {
        return new self(new PluginLogger());
    }

    public static function remember(ReservationContext $context): void
    {
        self::$accepted = $context;
    }

    public static function forget(): void
    {
        self::$accepted = null;
    }

    public function beforeAdd(CommonDBTM $item): void
    {
        $context = self::$accepted;
        if ($context === null || !$item instanceof Ticket || !is_array($item->input)) {
            return;
        }

        try {
            $item->input['name']    = self::title($context);
            $item->input['content'] = self::summary($context) . (string) ($item->input['content'] ?? '');
        } catch (Throwable $error) {
            $this->logger->error('coffee_ticket_details', $error, ['reservation_id' => $context->reservationId]);
        }
    }

    public static function title(ReservationContext $context): string
    {
        return sprintf(
            __('Coffee Break - %1$s - %2$s - Reservation #%3$d', 'reservationcoffee'),
            $context->roomName,
            self::formatDateTime($context->rawBegin),
            $context->reservationId,
        );
    }

    /**
     * @return string HTML
     */
    public static function summary(ReservationContext $context): string
    {
        return sprintf(
            '<p><strong>%s</strong><br>%s: %s<br>%s: %s<br>%s: %s</p>',
            htmlescape(sprintf(__('Reservation #%d, as booked', 'reservationcoffee'), $context->reservationId)),
            htmlescape(__('Room', 'reservationcoffee')),
            htmlescape($context->roomName),
            htmlescape(__('Start', 'reservationcoffee')),
            htmlescape(self::formatDateTime($context->rawBegin)),
            htmlescape(__('End', 'reservationcoffee')),
            htmlescape(self::formatDateTime($context->rawEnd)),
        );
    }

    private static function formatDateTime(string $raw): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw);

        return $date === false ? $raw : $date->format('d/m/Y H:i');
    }
}
