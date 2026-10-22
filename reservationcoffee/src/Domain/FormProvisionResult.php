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

final class FormProvisionResult
{
    /**
     * @param array<string, int> $questionIds logical key => question ID
     * @param list<string>       $messages
     */
    public function __construct(
        public readonly FormProvisionStatus $status,
        public readonly int $formId = 0,
        public readonly array $questionIds = [],
        public readonly array $messages = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->status !== FormProvisionStatus::FAILED;
    }
}
