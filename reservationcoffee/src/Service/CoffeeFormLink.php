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

use Glpi\Form\Form;
use Glpi\Form\Question;
use GlpiPlugin\Reservationcoffee\Config;

/**
 * Builds the internal URL of the native coffee break form. Only configured
 * IDs are used, so the redirect target can never be chosen by the client.
 */
final class CoffeeFormLink
{
    public function __construct(
        private readonly Config $config,
        private readonly string $rootDoc,
    ) {}

    /**
     * Returns null when the configured form (or question) no longer exists.
     */
    public function build(int $reservationId): ?string
    {
        if (!Form::getById($this->config->coffeeFormId) instanceof Form) {
            return null;
        }

        $url = sprintf('%s/Form/Render/%d', $this->rootDoc, $this->config->coffeeFormId);

        $question = $this->config->reservationQuestionId > 0
            ? Question::getById($this->config->reservationQuestionId)
            : null;
        if (
            !$question instanceof Question
            || (int) $question->getForm()->getID() !== $this->config->coffeeFormId
        ) {
            return $url;
        }

        // GLPI pre-fills a question from a GET parameter named after its UUID,
        // with dots replaced by underscores (see Question::setDefaultValueFromParameters()).
        $parameter = str_replace('.', '_', (string) $question->fields['uuid']);

        return $url . '?' . http_build_query([$parameter => $reservationId]);
    }
}
