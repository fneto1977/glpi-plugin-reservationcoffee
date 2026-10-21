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
use GlpiPlugin\Reservationcoffee\Domain\ReservationContext;
use Html;

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
     * Returns null when the configured form no longer exists. Configured
     * questions that are missing or belong to another form are not pre-filled.
     */
    public function build(ReservationContext $context, string $requesterName): ?string
    {
        if (!Form::getById($this->config->coffeeFormId) instanceof Form) {
            return null;
        }

        $url = sprintf('%s/Form/Render/%d', $this->rootDoc, $this->config->coffeeFormId);

        $values = [$this->config->reservationQuestionId => (string) $context->reservationId];
        foreach (self::prefillValues($context, $requesterName) as $key => $value) {
            $values[$this->config->prefillQuestionIds[$key] ?? 0] ??= $value;
        }

        $parameters = [];
        foreach ($values as $questionId => $value) {
            $question = $questionId > 0 ? Question::getById($questionId) : null;
            if (
                !$question instanceof Question
                || (int) $question->getForm()->getID() !== $this->config->coffeeFormId
            ) {
                continue;
            }
            // GLPI pre-fills a question from a GET parameter named after its UUID,
            // with dots replaced by underscores (see Question::setDefaultValueFromParameters()).
            $parameters[str_replace('.', '_', (string) $question->fields['uuid'])] = $value;
        }

        return $parameters === [] ? $url : $url . '?' . http_build_query($parameters);
    }

    /**
     * Date and time as GLPI displays them to the user: the reservation is
     * already stored in the session timezone and the date follows the user's format.
     *
     * @return array<string, string> value per `Config::PREFILL_KEYS` entry
     */
    public static function prefillValues(ReservationContext $context, string $requesterName): array
    {
        return [
            Config::KEY_REQUESTER_QUESTION_ID => $requesterName,
            Config::KEY_DATE_QUESTION_ID      => (string) Html::convDate($context->rawBegin),
            Config::KEY_TIME_QUESTION_ID      => substr($context->rawBegin, 11, 5),
            Config::KEY_ROOM_QUESTION_ID      => $context->roomName,
        ];
    }
}
