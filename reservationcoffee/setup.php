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

use Glpi\Form\AnswersSet;
use Glpi\Plugin\Hooks;

define('PLUGIN_RESERVATIONCOFFEE_VERSION', '0.1.0');

// Validated on 11.0.4 and 11.0.11; `max` is exclusive.
define('PLUGIN_RESERVATIONCOFFEE_MIN_GLPI', '11.0.4');
define('PLUGIN_RESERVATIONCOFFEE_MAX_GLPI', '12.0.0');

function plugin_init_reservationcoffee(): void
{
    global $PLUGIN_HOOKS;

    if (!Plugin::isPluginActive('reservationcoffee')) {
        return;
    }

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['reservationcoffee'] = [
        Reservation::class => 'plugin_reservationcoffee_item_add_reservation',
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['reservationcoffee'] = [
        AnswersSet::class => 'plugin_reservationcoffee_pre_item_add_answers_set',
    ];

    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['reservationcoffee'][] = 'js/pending.js';
}

/**
 * @return array<string, mixed>
 */
function plugin_version_reservationcoffee(): array
{
    return [
        'name'         => 'Reservation Coffee Break',
        'version'      => PLUGIN_RESERVATIONCOFFEE_VERSION,
        'author'       => 'Equipe interna',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/fneto1977/glpi-plugin-reservationcoffee',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_RESERVATIONCOFFEE_MIN_GLPI,
                'max' => PLUGIN_RESERVATIONCOFFEE_MAX_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_reservationcoffee_check_prerequisites(): bool
{
    return true;
}

/**
 * @param bool $verbose
 */
function plugin_reservationcoffee_check_config($verbose = false): bool
{
    return true;
}
