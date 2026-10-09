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

/**
 * Internal URLs, all relative to the GLPI base path (`root_doc`).
 */
final class OfferUrls
{
    public function __construct(private readonly string $rootDoc) {}

    public static function fromGlpiConfig(): self
    {
        global $CFG_GLPI;

        return new self(rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/'));
    }

    public function rootDoc(): string
    {
        return $this->rootDoc;
    }

    public function offer(int $reservationId): string
    {
        return sprintf('%s/plugins/reservationcoffee/offer/%d', $this->rootDoc, $reservationId);
    }

    public function accept(int $reservationId): string
    {
        return $this->offer($reservationId) . '/accept';
    }

    public function decline(int $reservationId): string
    {
        return $this->offer($reservationId) . '/decline';
    }

    public function calendar(): string
    {
        return $this->rootDoc . '/front/reservationitem.php';
    }

    public function serviceCatalog(): string
    {
        return $this->rootDoc . '/ServiceCatalog';
    }
}
