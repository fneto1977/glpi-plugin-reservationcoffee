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
use Glpi\Form\AnswersSet;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\CoffeeEligibility;
use GlpiPlugin\Reservationcoffee\Domain\SystemClock;
use Session;
use Throwable;

/**
 * `pre_item_add` handler for form answers. Re-applies the 48h rule on the
 * server whenever the coffee break form is submitted, including when it is
 * opened directly from the service catalog. Cancelling the `add()` makes GLPI
 * roll back the submission, so no ticket is created.
 */
final class CoffeeRequestGuard
{
    public function __construct(
        private readonly Config $config,
        private readonly ReservationResolver $resolver,
        private readonly CoffeeEligibility $eligibility,
        private readonly PluginLogger $logger,
    ) {}

    public static function create(): self
    {
        $config = Config::load();

        return new self(
            $config,
            new ReservationResolver($config),
            new CoffeeEligibility(new SystemClock(), Config::MIN_NOTICE_SECONDS),
            new PluginLogger(),
        );
    }

    public function beforeAdd(CommonDBTM $item): void
    {
        if (!$item instanceof AnswersSet || !is_array($item->input)) {
            return;
        }

        $formId = (int) ($item->input['forms_forms_id'] ?? 0);
        if ($this->config->coffeeFormId <= 0 || $formId !== $this->config->coffeeFormId) {
            return;
        }

        try {
            $rejection = $this->findRejection($item->input);
        } catch (Throwable $error) {
            $this->logger->error('guard_coffee_request', $error, ['form_id' => $formId]);
            $rejection = __s('The coffee break request could not be validated. Please try again later.', 'reservationcoffee');
        }

        if ($rejection !== null) {
            Session::addMessageAfterRedirect($rejection, false, ERROR);
            $item->input = false;
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return string|null HTML-escaped message, or null when the request is valid
     */
    private function findRejection(array $input): ?string
    {
        if (!$this->config->isGuardEnabled()) {
            $this->logger->warning('guard_coffee_request', PluginLogger::CATEGORY_CONFIGURATION);
            return __s('The coffee break request is not available at the moment.', 'reservationcoffee');
        }

        $usersId       = (int) ($input['users_id'] ?? 0);
        $reservationId = $this->extractReservationId((string) ($input['answers'] ?? ''));
        if ($reservationId === null) {
            $this->logger->warning('guard_coffee_request', PluginLogger::CATEGORY_VALIDATION, ['users_id' => $usersId]);
            return __s('Please provide a valid reservation ID.', 'reservationcoffee');
        }

        $context = $this->resolver->load($reservationId);
        if ($context === null || !$this->resolver->canRequestCoffee($reservationId, $usersId)) {
            $this->logger->warning('guard_coffee_request', PluginLogger::CATEGORY_AUTHORIZATION, [
                'users_id'       => $usersId,
                'reservation_id' => $reservationId,
            ]);
            return __s('This reservation was not found or does not allow a coffee break request.', 'reservationcoffee');
        }

        if (!$this->eligibility->isEligible($context->begin)) {
            return __s('A coffee break must be requested at least 48 hours before the meeting starts.', 'reservationcoffee');
        }

        return null;
    }

    private function extractReservationId(string $json): ?int
    {
        $answers = json_decode($json, true);
        if (!is_array($answers)) {
            return null;
        }

        foreach ($answers as $answer) {
            if (!is_array($answer) || (int) ($answer['question_id'] ?? 0) !== $this->config->reservationQuestionId) {
                continue;
            }

            $raw = $answer['raw_answer'] ?? null;
            if (is_int($raw) && $raw > 0) {
                return $raw;
            }
            if (is_string($raw) && preg_match('/^\s*(\d{1,10})\s*$/', $raw, $matches) === 1 && (int) $matches[1] > 0) {
                return (int) $matches[1];
            }

            return null;
        }

        return null;
    }
}
