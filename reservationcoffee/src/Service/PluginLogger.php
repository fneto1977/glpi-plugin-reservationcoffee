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

use Throwable;
use Toolbox;

/**
 * Writes to `files/_log/reservationcoffee.log`. Only identifiers and error
 * categories are logged; never names, comments, answers or session data.
 */
final class PluginLogger
{
    public const CATEGORY_VALIDATION     = 'VALIDATION_ERROR';
    public const CATEGORY_AUTHORIZATION  = 'AUTHORIZATION_ERROR';
    public const CATEGORY_NOT_FOUND      = 'NOT_FOUND';
    public const CATEGORY_CONFIGURATION  = 'CONFIGURATION_ERROR';
    public const CATEGORY_UNKNOWN        = 'UNKNOWN_ERROR';

    private const LOG_FILE = 'reservationcoffee';

    /**
     * @param array<string, int|string|bool> $ids
     */
    public function warning(string $operation, string $category, array $ids = []): void
    {
        $this->write('WARNING', $operation, $category, $ids, null);
    }

    /**
     * @param array<string, int|string|bool> $ids
     */
    public function error(string $operation, Throwable $error, array $ids = []): void
    {
        $this->write('ERROR', $operation, self::CATEGORY_UNKNOWN, $ids, $error);
    }

    /**
     * @param array<string, int|string|bool> $ids
     */
    private function write(string $level, string $operation, string $category, array $ids, ?Throwable $error): void
    {
        $parts = [sprintf('level=%s operation=%s category=%s', $level, $operation, $category)];
        foreach ($ids as $key => $value) {
            $parts[] = sprintf('%s=%s', $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
        if ($error !== null) {
            $parts[] = sprintf(
                'exception=%s at=%s:%d',
                $error::class,
                basename($error->getFile()),
                $error->getLine(),
            );
        }

        Toolbox::logInFile(self::LOG_FILE, implode(' ', $parts) . "\n");
    }
}
