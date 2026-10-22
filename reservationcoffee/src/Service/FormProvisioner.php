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

use AbstractRightsDropdown;
use Glpi\Form\AccessControl\ControlType\AllowList;
use Glpi\Form\AccessControl\ControlType\AllowListConfig;
use Glpi\Form\AccessControl\FormAccessControl;
use Glpi\Form\Condition\CreationStrategy;
use Glpi\Form\Condition\LogicOperator;
use Glpi\Form\Condition\ValidationStrategy;
use Glpi\Form\Condition\ValueOperator;
use Glpi\Form\Condition\VisibilityStrategy;
use Glpi\Form\Destination\CommonITILField\AssigneeField;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldConfig;
use Glpi\Form\Destination\CommonITILField\ITILActorFieldStrategy;
use Glpi\Form\Destination\CommonITILField\ITILCategoryField;
use Glpi\Form\Destination\CommonITILField\ITILCategoryFieldConfig;
use Glpi\Form\Destination\CommonITILField\ITILCategoryFieldStrategy;
use Glpi\Form\Destination\CommonITILField\SimpleValueConfig;
use Glpi\Form\Destination\CommonITILField\TitleField;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use Glpi\Form\Question;
use Glpi\Form\QuestionType\QuestionTypeCheckbox;
use Glpi\Form\QuestionType\QuestionTypeLongText;
use Glpi\Form\QuestionType\QuestionTypeNumber;
use Glpi\Form\QuestionType\QuestionTypeRadio;
use Glpi\Form\QuestionType\QuestionTypeSelectableExtraDataConfig;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Form\Section;
use Glpi\Form\Tag\AnswerTagProvider;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\FormProvisionResult;
use GlpiPlugin\Reservationcoffee\Domain\FormProvisionStatus;
use Group;
use ITILCategory;
use RuntimeException;
use Throwable;

final class FormProvisioner
{
    public const DEFAULT_FORM_NAME = 'Solicitação de Coffee Break';

    public function __construct(private readonly PluginLogger $logger) {}

    public static function create(): self
    {
        return new self(new PluginLogger());
    }

    /**
     * Provisions the default form if no form with DEFAULT_FORM_NAME exists.
     * If one already exists, preserves it and wires the configuration if unset.
     */
    public function provisionIfNeeded(int $entityId = 0): FormProvisionResult
    {
        try {
            $form = new Form();
            if ($form->getFromDBByCrit(['name' => self::DEFAULT_FORM_NAME])) {
                $formId = (int) $form->getID();
                $questionIds = $this->resolveExistingQuestionIds($formId);
                $this->syncConfigIfUnset($formId, $questionIds);

                return new FormProvisionResult(
                    FormProvisionStatus::ALREADY_EXISTS,
                    $formId,
                    $questionIds,
                    [__('The default coffee break form already exists.', 'reservationcoffee')],
                );
            }

            return $this->createDefaultForm($entityId);
        } catch (Throwable $error) {
            $this->logger->error('provision_default_form', $error);

            return new FormProvisionResult(
                FormProvisionStatus::FAILED,
                0,
                [],
                [$error->getMessage()],
            );
        }
    }

    private function createDefaultForm(int $entityId): FormProvisionResult
    {
        $form = new Form();
        $formId = $form->add([
            'name'                  => self::DEFAULT_FORM_NAME,
            'entities_id'           => $entityId,
            'is_recursive'          => 1,
            'is_active'             => 1,
            'header'                => __('Solicite o Coffee Break para a sua reunião (mínimo de 48 horas de antecedência).', 'reservationcoffee'),
            '_init_access_policies' => false,
        ]);
        if (!$formId) {
            throw new RuntimeException('Unable to create the default coffee break form');
        }
        $formId = (int) $formId;

        $accessControl = new FormAccessControl();
        $accessControl->add([
            Form::getForeignKeyField() => $formId,
            'strategy'                 => AllowList::class,
            '_config'                  => new AllowListConfig(user_ids: [AbstractRightsDropdown::ALL_USERS]),
            'is_active'                => 1,
        ]);

        $section = new Section();
        if (!$section->getFromDBByCrit(['forms_forms_id' => $formId])) {
            $sectionId = $section->add([
                'forms_forms_id' => $formId,
                'name'           => __('Geral', 'reservationcoffee'),
            ]);
            if (!$sectionId) {
                throw new RuntimeException('Unable to create the default form section');
            }
        }
        $sectionId = (int) $section->getID();

        $questions = $this->createQuestions($sectionId);
        $this->configureDestination($formId, $questions, $entityId);

        $questionIds = array_map(static fn(Question $q): int => (int) $q->getID(), $questions);
        $this->saveConfig($formId, $questionIds);

        return new FormProvisionResult(
            FormProvisionStatus::CREATED,
            $formId,
            $questionIds,
            [__('Default coffee break form successfully created.', 'reservationcoffee')],
        );
    }

    /**
     * @return array<string, Question>
     */
    private function createQuestions(int $sectionId): array
    {
        $plain = static fn(string $type, int $rank, bool $mandatory, string $description = ''): array => [
            'type'                   => $type,
            'vertical_rank'          => $rank,
            'is_mandatory'           => $mandatory ? 1 : 0,
            'description'            => $description,
            'default_value'          => '',
            'extra_data'             => '',
            'visibility_strategy'    => VisibilityStrategy::ALWAYS_VISIBLE->value,
            '_conditions'            => [],
            'validation_strategy'    => ValidationStrategy::NO_VALIDATION->value,
            '_validation_conditions' => [],
        ];

        $questions = [];
        $questions['reservation_id'] = $this->upsertQuestion($sectionId, 'ID da reserva', $plain(QuestionTypeNumber::class, 0, true));
        $questions['requester']      = $this->upsertQuestion($sectionId, 'Solicitante', $plain(QuestionTypeShortText::class, 1, false));
        $questions['date']           = $this->upsertQuestion($sectionId, 'Data', $plain(QuestionTypeShortText::class, 2, false));
        $questions['start_time']     = $this->upsertQuestion($sectionId, 'Horário de início', $plain(QuestionTypeShortText::class, 3, false));
        $questions['room']           = $this->upsertQuestion($sectionId, 'Sala', $plain(QuestionTypeShortText::class, 4, false));
        $questions['participants']   = $this->upsertQuestion(
            $sectionId,
            'Participantes',
            $plain(QuestionTypeNumber::class, 5, true, 'Número inteiro maior que zero.'),
            ['Quantidade de participantes'],
        );

        $participants = $questions['participants'];
        $participants->update([
            'id'                     => $participants->getID(),
            'validation_strategy'    => ValidationStrategy::VALID_IF->value,
            '_validation_conditions' => [[
                'item'           => 'question-' . $participants->fields['uuid'],
                'value_operator' => ValueOperator::MATCH_REGEX->value,
                'value'          => '/^[1-9][0-9]{0,3}$/',
                'logic_operator' => LogicOperator::AND->value,
            ]],
        ]);

        $questions['needs_coffee'] = $this->upsertQuestion($sectionId, 'Necessita de Coffee Break', [
            'default_value' => 'sim',
            'extra_data'    => $this->encodeOptions(['sim' => 'Sim', 'nao' => 'Não']),
        ] + $plain(QuestionTypeRadio::class, 6, true));

        $questions['items'] = $this->upsertQuestion($sectionId, 'Itens solicitados', [
            'extra_data' => $this->encodeOptions([
                'cafe'         => 'Café',
                'agua'         => 'Água',
                'refrigerante' => 'Refrigerante',
                'suco'         => 'Suco',
                'salgados'     => 'Salgados',
                'doces'        => 'Doces',
            ]),
            'visibility_strategy' => VisibilityStrategy::VISIBLE_IF->value,
            '_conditions'         => [[
                'item'           => 'question-' . $questions['needs_coffee']->fields['uuid'],
                'value_operator' => ValueOperator::EQUALS->value,
                'value'          => 'sim',
                'logic_operator' => LogicOperator::AND->value,
            ]],
        ] + $plain(QuestionTypeCheckbox::class, 7, true));

        $questions['notes'] = $this->upsertQuestion(
            $sectionId,
            'Observações',
            $plain(QuestionTypeLongText::class, 8, false),
            ['Restrições alimentares / observações'],
        );

        return $questions;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string>         $previousNames
     */
    private function upsertQuestion(int $sectionId, string $name, array $attributes, array $previousNames = []): Question
    {
        $question = new Question();
        foreach ([$name, ...$previousNames] as $candidate) {
            if ($question->getFromDBByCrit(['forms_sections_id' => $sectionId, 'name' => $candidate])) {
                $question->update(['id' => $question->getID(), 'name' => $name] + $attributes);
                return Question::getById($question->getID());
            }
        }

        $id = $question->add(['forms_sections_id' => $sectionId, 'name' => $name] + $attributes);
        if (!$id) {
            throw new RuntimeException(sprintf('Unable to create question "%s"', $name));
        }

        return Question::getById($id);
    }

    /**
     * @param array<string, string> $options
     */
    private function encodeOptions(array $options): string
    {
        return json_encode([QuestionTypeSelectableExtraDataConfig::OPTIONS => $options], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, Question> $questions
     */
    private function configureDestination(int $formId, array $questions, int $entityId): void
    {
        $destination = new FormDestination();
        if (!$destination->getFromDBByCrit(['forms_forms_id' => $formId, 'itemtype' => FormDestinationTicket::class])) {
            $destId = $destination->add([
                'forms_forms_id' => $formId,
                'itemtype'       => FormDestinationTicket::class,
                'name'           => 'Ticket Coffee Break',
            ]);
            if (!$destId || !$destination->getFromDB($destId)) {
                throw new RuntimeException('Unable to create the ticket destination');
            }
        }

        $categoryId = $this->findOrCreateCategory($entityId);
        $groupId    = $this->findOrCreateGroup($entityId);

        $tag = static fn(string $key): string => (new AnswerTagProvider())->getTagForQuestion($questions[$key])->html;

        $config = [
            TitleField::getKey() => [
                SimpleValueConfig::VALUE => sprintf(
                    'Coffee Break - %s - %s %s - Reserva #%s',
                    $tag('room'),
                    $tag('date'),
                    $tag('start_time'),
                    $tag('reservation_id'),
                ),
            ],
        ];

        if ($categoryId > 0) {
            $config[ITILCategoryField::getKey()] = [
                ITILCategoryFieldConfig::STRATEGY                 => ITILCategoryFieldStrategy::SPECIFIC_VALUE->value,
                ITILCategoryFieldConfig::SPECIFIC_ITILCATEGORY_ID => $categoryId,
            ];
        }

        if ($groupId > 0) {
            $config[AssigneeField::getKey()] = [
                ITILActorFieldConfig::STRATEGIES              => [ITILActorFieldStrategy::SPECIFIC_VALUES->value],
                ITILActorFieldConfig::SPECIFIC_ITILACTORS_IDS => ['groups_id-' . $groupId],
            ];
        }

        $destination->update([
            'id'                => $destination->getID(),
            '_from_import'      => true,
            'creation_strategy' => CreationStrategy::CREATED_IF->value,
            '_conditions'       => [[
                'item'           => 'question-' . $questions['needs_coffee']->fields['uuid'],
                'value_operator' => ValueOperator::EQUALS->value,
                'value'          => 'sim',
                'logic_operator' => LogicOperator::AND->value,
            ]],
            'config'            => $config + $destination->getConfig(),
        ]);
    }

    private function findOrCreateCategory(int $entityId): int
    {
        $parent = new ITILCategory();
        if (!$parent->getFromDBByCrit(['name' => 'Administrativo', 'itilcategories_id' => 0])) {
            $parentId = $parent->add([
                'name'               => 'Administrativo',
                'itilcategories_id'  => 0,
                'entities_id'        => $entityId,
                'is_recursive'       => 1,
            ]);
        } else {
            $parentId = (int) $parent->getID();
        }

        $category = new ITILCategory();
        if (!$category->getFromDBByCrit(['name' => 'Coffee Break', 'itilcategories_id' => $parentId])) {
            $catId = $category->add([
                'name'               => 'Coffee Break',
                'itilcategories_id'  => $parentId,
                'entities_id'        => $entityId,
                'is_recursive'       => 1,
            ]);
            return (int) $catId;
        }

        return (int) $category->getID();
    }

    private function findOrCreateGroup(int $entityId): int
    {
        $group = new Group();
        if (!$group->getFromDBByCrit(['name' => 'Facilities / Copa', 'entities_id' => $entityId])) {
            $groupId = $group->add([
                'name'         => 'Facilities / Copa',
                'entities_id'  => $entityId,
                'is_recursive' => 1,
                'is_assign'    => 1,
                'is_requester' => 0,
            ]);
            return (int) $groupId;
        }

        return (int) $group->getID();
    }

    /**
     * @return array<string, int>
     */
    public function resolveExistingQuestionIds(int $formId): array
    {
        $questionIds = [];
        $section = new Section();
        if ($section->getFromDBByCrit(['forms_forms_id' => $formId])) {
            $question = new Question();
            foreach ($question->find(['forms_sections_id' => $section->getID()]) as $row) {
                $name = (string) $row['name'];
                $id   = (int) $row['id'];
                match ($name) {
                    'ID da reserva'                             => $questionIds['reservation_id'] = $id,
                    'Solicitante'                               => $questionIds['requester'] = $id,
                    'Data'                                      => $questionIds['date'] = $id,
                    'Horário de início'                         => $questionIds['start_time'] = $id,
                    'Sala'                                      => $questionIds['room'] = $id,
                    'Participantes', 'Quantidade de participantes' => $questionIds['participants'] = $id,
                    'Necessita de Coffee Break'                 => $questionIds['needs_coffee'] = $id,
                    'Itens solicitados'                         => $questionIds['items'] = $id,
                    'Observações', 'Restrições alimentares / observações' => $questionIds['notes'] = $id,
                    default                                     => null,
                };
            }
        }

        return $questionIds;
    }

    /**
     * @param array<string, int> $questionIds
     */
    private function syncConfigIfUnset(int $formId, array $questionIds): void
    {
        $current = Config::load();
        if ($current->coffeeFormId <= 0) {
            $this->saveConfig($formId, $questionIds);
        }
    }

    /**
     * @param array<string, int> $questionIds
     */
    private function saveConfig(int $formId, array $questionIds): void
    {
        $values = [
            Config::KEY_COFFEE_FORM_ID          => (string) $formId,
            Config::KEY_RESERVATION_QUESTION_ID => (string) ($questionIds['reservation_id'] ?? 0),
        ];

        $optionalKeys = [
            'requester'    => Config::KEY_REQUESTER_QUESTION_ID,
            'date'         => Config::KEY_DATE_QUESTION_ID,
            'start_time'   => Config::KEY_TIME_QUESTION_ID,
            'room'         => Config::KEY_ROOM_QUESTION_ID,
            'participants' => Config::KEY_PARTICIPANTS_QUESTION_ID,
        ];

        foreach ($optionalKeys as $logical => $configKey) {
            if (isset($questionIds[$logical]) && $questionIds[$logical] > 0) {
                $values[$configKey] = (string) $questionIds[$logical];
            }
        }

        Config::save($values);
    }
}
