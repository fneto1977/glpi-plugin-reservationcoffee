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

namespace GlpiPlugin\Reservationcoffee\Controller;

use Glpi\Application\View\TemplateRenderer;
use Glpi\Form\Form;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\FormProvisionStatus;
use GlpiPlugin\Reservationcoffee\Service\FormProvisioner;
use Html;
use Plugin;
use ReservationItem;
use Session;

final class ConfigController
{
    /**
     * Renders the configuration view.
     *
     * @param bool $echo Whether to output the rendered HTML directly.
     * @return string The rendered HTML.
     */
    public function render(bool $echo = true): string
    {
        Session::checkRight('config', UPDATE);

        $config = Config::load();
        $reservableItems = $this->getReservableItems();
        $forms = $this->getAvailableForms();
        $questions = $this->getQuestionsForForm($config->coffeeFormId);

        $status = [
            'is_operational'   => $config->isOfferEnabled() && $config->isGuardEnabled(),
            'is_offer_enabled' => $config->isOfferEnabled(),
            'is_guard_enabled' => $config->isGuardEnabled(),
            'has_rooms'        => count($config->reservableItemIds) > 0,
            'has_form'         => $config->coffeeFormId > 0,
            'has_res_question' => $config->reservationQuestionId > 0,
            'invalid_keys'     => $config->invalidKeys,
        ];

        $context = [
            'config'           => $config,
            'reservable_items' => $reservableItems,
            'forms'            => $forms,
            'questions'        => $questions,
            'status'           => $status,
            'csrf_token'       => Session::getNewCSRFToken(),
            'action_url'       => $this->getConfigUrl(),
            'ajax_url'         => Plugin::getWebDir('reservationcoffee') . '/ajax/form_questions.php',
            'js_url'           => Plugin::getWebDir('reservationcoffee') . '/public/js/config.js',
            'default_form_name' => FormProvisioner::DEFAULT_FORM_NAME,
        ];

        $html = TemplateRenderer::getInstance()->render('@reservationcoffee/config.html.twig', $context);

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    /**
     * Saves the submitted configuration.
     *
     * @param array<string, mixed> $input
     * @param bool                 $redirect
     */
    public function save(array $input, bool $redirect = true): void
    {
        Session::checkRight('config', UPDATE);
        if (!isset($input['_glpi_csrf_token']) || $input['_glpi_csrf_token'] === '' || isset($_SESSION['glpicsrftokens'][$input['_glpi_csrf_token']])) {
            Session::checkCSRF($input);
        }

        // Normalize reservable_item_ids from array or string
        $rawItemIds = $input[Config::KEY_RESERVABLE_ITEM_IDS] ?? [];
        if (is_array($rawItemIds)) {
            $parsedIds = array_filter(array_map('intval', $rawItemIds), static fn(int $id): bool => $id > 0);
            $itemIdsStr = implode(',', array_values(array_unique($parsedIds)));
        } elseif (is_string($rawItemIds)) {
            $itemIdsStr = trim($rawItemIds);
        } else {
            $itemIdsStr = '';
        }

        $formId = isset($input[Config::KEY_COFFEE_FORM_ID]) ? (int) $input[Config::KEY_COFFEE_FORM_ID] : 0;
        $resQuestionId = isset($input[Config::KEY_RESERVATION_QUESTION_ID]) ? (int) $input[Config::KEY_RESERVATION_QUESTION_ID] : 0;
        $ttl = isset($input[Config::KEY_PENDING_TTL_SECONDS]) ? (int) $input[Config::KEY_PENDING_TTL_SECONDS] : Config::DEFAULT_PENDING_TTL_SECONDS;
        $ttl = max(Config::MIN_PENDING_TTL_SECONDS, min(Config::MAX_PENDING_TTL_SECONDS, $ttl));

        $values = [
            Config::KEY_RESERVABLE_ITEM_IDS     => $itemIdsStr,
            Config::KEY_COFFEE_FORM_ID          => $formId > 0 ? (string) $formId : '',
            Config::KEY_RESERVATION_QUESTION_ID => $resQuestionId > 0 ? (string) $resQuestionId : '',
            Config::KEY_PENDING_TTL_SECONDS     => (string) $ttl,
        ];

        $optionalKeys = [
            Config::KEY_REQUESTER_QUESTION_ID,
            Config::KEY_DATE_QUESTION_ID,
            Config::KEY_TIME_QUESTION_ID,
            Config::KEY_ROOM_QUESTION_ID,
            Config::KEY_PARTICIPANTS_QUESTION_ID,
        ];

        foreach ($optionalKeys as $key) {
            $val = isset($input[$key]) ? (int) $input[$key] : 0;
            $values[$key] = $val > 0 ? (string) $val : '';
        }

        Config::save($values);

        Session::addMessageAfterRedirect(
            __('Configuration saved successfully.', 'reservationcoffee'),
            false,
            INFO,
        );

        if ($redirect) {
            Html::redirect($this->getConfigUrl());
        }
    }

    /**
     * Executes 1-click provisioning of the default form and auto-wires its settings.
     *
     * @param bool $redirect
     */
    public function provision(bool $redirect = true): void
    {
        Session::checkRight('config', UPDATE);
        if (!isset($_POST['_glpi_csrf_token']) || $_POST['_glpi_csrf_token'] === '' || isset($_SESSION['glpicsrftokens'][$_POST['_glpi_csrf_token']])) {
            Session::checkCSRF($_POST);
        }

        $provisioner = FormProvisioner::create();
        $result = $provisioner->provisionIfNeeded();

        if ($result->status === FormProvisionStatus::CREATED) {
            Session::addMessageAfterRedirect(
                __('Default coffee break form created and configured successfully.', 'reservationcoffee'),
                false,
                INFO,
            );
        } elseif ($result->status === FormProvisionStatus::ALREADY_EXISTS) {
            Session::addMessageAfterRedirect(
                __('The default coffee break form already exists. Associated configuration updated.', 'reservationcoffee'),
                false,
                INFO,
            );
        } else {
            Session::addMessageAfterRedirect(
                sprintf(__('Failed to provision form: %s', 'reservationcoffee'), implode(', ', $result->messages)),
                false,
                ERROR,
            );
        }

        if ($redirect) {
            Html::redirect($this->getConfigUrl());
        }
    }

    /**
     * Returns all active reservable items with their display name.
     *
     * @return array<int, string> [reservationitems_id => asset name]
     */
    public function getReservableItems(): array
    {
        global $DB;

        $items = [];
        foreach ($DB->request(['FROM' => ReservationItem::getTable(), 'ORDER' => 'id ASC']) as $row) {
            $item = getItemForItemtype((string) $row['itemtype']);
            if ($item !== false && $item->getFromDB((int) $row['items_id'])) {
                $items[(int) $row['id']] = $item->getName();
            }
        }

        return $items;
    }

    /**
     * Returns all active forms in GLPI.
     *
     * @return array<int, string> [forms_forms_id => form name]
     */
    public function getAvailableForms(): array
    {
        $forms = [];
        $formObj = new Form();
        foreach ($formObj->find(['is_active' => 1], 'name ASC') as $row) {
            $forms[(int) $row['id']] = (string) $row['name'];
        }

        return $forms;
    }

    /**
     * Returns questions for a specific form.
     *
     * @return list<array{id: int, name: string, type: string}>
     */
    public function getQuestionsForForm(int $formId): array
    {
        if ($formId <= 0) {
            return [];
        }

        $form = Form::getById($formId);
        if (!$form instanceof Form) {
            return [];
        }

        $questions = [];
        foreach ($form->getQuestions() as $q) {
            $questions[] = [
                'id'   => (int) $q->getID(),
                'name' => (string) $q->fields['name'],
                'type' => (string) $q->fields['type'],
            ];
        }

        return $questions;
    }

    public function getConfigUrl(): string
    {
        return Plugin::getWebDir('reservationcoffee') . '/front/config.form.php';
    }
}
