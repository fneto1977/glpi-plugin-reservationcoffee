<?php

/**
 * Idempotent synthetic data seed for the local homologation / E2E stack.
 *
 * Runs inside the GLPI container, as www-data:
 *   docker compose ... exec -T -u www-data -e E2E_PASSWORD_SELFSERVICE=... glpi \
 *     php /opt/reservationcoffee-tools/seed_e2e.php
 *
 * Prints a JSON document with the created IDs on stdout. Never run it against
 * a production database. For a remote homologation server (tools/remote-e2e.sh)
 * E2E_GLPI_ROOT, E2E_SEED_ROOM_ITEMS and E2E_SEED_NON_ROOM_ITEM select the GLPI
 * root and existing reservable items instead of creating synthetic ones.
 */

declare(strict_types=1);

use Glpi\Form\AccessControl\ControlType\AllowList;
use Glpi\Form\AccessControl\ControlType\AllowListConfig;
use Glpi\Form\AccessControl\FormAccessControl;
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
use Glpi\Form\QuestionType\QuestionTypeLongText;
use Glpi\Form\QuestionType\QuestionTypeNumber;
use Glpi\Form\QuestionType\QuestionTypeShortText;
use Glpi\Form\Section;
use Glpi\Form\Tag\AnswerTagProvider;
use GlpiPlugin\Reservationcoffee\Config as PluginConfig;

define('GLPI_ROOT_DIR', getenv('E2E_GLPI_ROOT') ?: '/var/www/glpi');

chdir(GLPI_ROOT_DIR);
require GLPI_ROOT_DIR . '/vendor/autoload.php';

// Booting the console application initialises the database, configuration and plugins.
new Glpi\Console\Application(new Glpi\Kernel\Kernel('production'));

// Run as GLPI's CLI automatic-action identity, like cron tasks do, so that
// rights-filtered inputs (e.g. entity names) are not dropped.
$_SESSION['glpicronuserrunning'] = 'reservationcoffee-e2e-seed';

/**
 * @param class-string<CommonDBTM> $itemtype
 * @param array<string, mixed>     $criteria
 * @param array<string, mixed>     $input
 */
function seed_find_or_create(string $itemtype, array $criteria, array $input = []): int
{
    $item = new $itemtype();
    if ($item->getFromDBByCrit($criteria)) {
        return (int) $item->getID();
    }

    $id = $item->add($criteria + $input);
    if (!$id) {
        throw new RuntimeException(sprintf('Unable to create %s %s', $itemtype, json_encode($criteria)));
    }

    return (int) $id;
}

function seed_profile_id(string $name): int
{
    $profile = new Profile();
    if (!$profile->getFromDBByCrit(['name' => $name])) {
        throw new RuntimeException(sprintf('Profile "%s" not found', $name));
    }

    return (int) $profile->getID();
}

function seed_required_env(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException(sprintf('Environment variable %s is required', $name));
    }

    return $value;
}

function seed_user(string $login, string $passwordEnv, int $profileId, int $entityId, bool $recursive, string $language): int
{
    $user = new User();
    if ($user->getFromDBByCrit(['name' => $login])) {
        return (int) $user->getID();
    }

    $password = seed_required_env($passwordEnv);
    $id = $user->add([
        'name'          => $login,
        'realname'      => $login,
        'password'      => $password,
        'password2'     => $password,
        'language'      => $language,
        '_profiles_id'  => $profileId,
        '_entities_id'  => $entityId,
        '_is_recursive' => $recursive ? 1 : 0,
        'is_active'     => 1,
    ]);
    if (!$id) {
        throw new RuntimeException(sprintf('Unable to create user %s', $login));
    }

    return (int) $id;
}

/**
 * @return array{asset_id: int, reservationitem_id: int}
 */
function seed_reservable_peripheral(string $name): array
{
    $assetId = seed_find_or_create(Peripheral::class, ['name' => $name], [
        'entities_id'  => 0,
        'is_recursive' => 1,
    ]);
    $reservationItemId = seed_find_or_create(ReservationItem::class, [
        'itemtype' => Peripheral::class,
        'items_id' => $assetId,
    ], [
        'entities_id'  => 0,
        'is_recursive' => 1,
        'is_active'    => 1,
    ]);

    return ['asset_id' => $assetId, 'reservationitem_id' => $reservationItemId];
}

/**
 * Uses an existing reservable item (remote homologation keeps its real rooms).
 *
 * @return array{name: string, asset_id: int, reservationitem_id: int}
 */
function seed_existing_reservable(int $reservationItemId): array
{
    $reservationItem = new ReservationItem();
    if (!$reservationItem->getFromDB($reservationItemId) || !(bool) $reservationItem->fields['is_active']) {
        throw new RuntimeException(sprintf('Active reservation item %d not found', $reservationItemId));
    }
    $asset = $reservationItem->getItem();
    if (!$asset instanceof CommonDBTM) {
        throw new RuntimeException(sprintf('Reservation item %d has no asset', $reservationItemId));
    }

    return [
        'name'               => (string) $asset->fields['name'],
        'asset_id'           => (int) $asset->getID(),
        'reservationitem_id' => $reservationItemId,
    ];
}

/**
 * @return list<int>
 */
function seed_id_list(string $name): array
{
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    return array_map('intval', explode(',', $value));
}

function seed_question(int $sectionId, string $name, string $type, int $rank, bool $mandatory): int
{
    return seed_find_or_create(Question::class, [
        'forms_sections_id' => $sectionId,
        'name'              => $name,
    ], [
        'type'          => $type,
        'vertical_rank' => $rank,
        'is_mandatory'  => $mandatory ? 1 : 0,
    ]);
}

$subEntityId = seed_find_or_create(Entity::class, ['name' => 'E2E Filial', 'entities_id' => 0]);

$existingRoomIds = seed_id_list('E2E_SEED_ROOM_ITEMS');
$existingNonRoomIds = seed_id_list('E2E_SEED_NON_ROOM_ITEM');
if ($existingRoomIds !== []) {
    if (count($existingRoomIds) !== 4 || count($existingNonRoomIds) !== 1) {
        throw new RuntimeException('E2E_SEED_ROOM_ITEMS needs 4 IDs and E2E_SEED_NON_ROOM_ITEM 1 ID');
    }
    $rooms = array_map('seed_existing_reservable', $existingRoomIds);
    $nonRoom = seed_existing_reservable($existingNonRoomIds[0]);
} else {
    $rooms = [];
    foreach (['Sala de Reunião 01', 'Sala de Reunião 02', 'Sala de Reunião 03', 'Sala de Reunião 04'] as $roomName) {
        $rooms[] = ['name' => $roomName] + seed_reservable_peripheral($roomName);
    }
    $nonRoom = ['name' => 'Projetor Portátil E2E'] + seed_reservable_peripheral('Projetor Portátil E2E');
}

$selfServiceProfileId = seed_profile_id('Self-Service');
$technicianProfileId  = seed_profile_id('Technician');

$users = [
    'selfservice'  => seed_user('e2e.selfservice', 'E2E_PASSWORD_SELFSERVICE', $selfServiceProfileId, 0, true, 'pt_BR'),
    'selfservice2' => seed_user('e2e.selfservice2', 'E2E_PASSWORD_SELFSERVICE2', $selfServiceProfileId, 0, true, 'pt_BR'),
    'tech'         => seed_user('e2e.tech', 'E2E_PASSWORD_TECH', $technicianProfileId, 0, true, 'pt_BR'),
    'otherentity'  => seed_user('e2e.otherentity', 'E2E_PASSWORD_OTHERENTITY', $selfServiceProfileId, $subEntityId, false, 'pt_BR'),
];

$groupId = seed_find_or_create(Group::class, ['name' => 'Facilities / Copa', 'entities_id' => 0], [
    'is_recursive' => 1,
    'is_assign'    => 1,
    'is_requester' => 0,
]);
$parentCategoryId = seed_find_or_create(ITILCategory::class, ['name' => 'Administrativo', 'itilcategories_id' => 0], [
    'entities_id'  => 0,
    'is_recursive' => 1,
]);
$categoryId = seed_find_or_create(ITILCategory::class, ['name' => 'Coffee Break', 'itilcategories_id' => $parentCategoryId], [
    'entities_id'  => 0,
    'is_recursive' => 1,
]);

$formName = 'Solicitação de Coffee Break';
$form = new Form();
$formCreated = false;
if (!$form->getFromDBByCrit(['name' => $formName])) {
    $formId = $form->add([
        'name'                  => $formName,
        'entities_id'           => 0,
        'is_recursive'          => 1,
        'is_active'             => 1,
        'header'                => 'Solicite o Coffee Break para a sua reunião (mínimo de 48 horas de antecedência).',
        '_init_access_policies' => false,
    ]);
    if (!$formId) {
        throw new RuntimeException('Unable to create the coffee break form');
    }
    $form->getFromDB($formId);
    $formCreated = true;
}
$formId = (int) $form->getID();

$section = new Section();
if (!$section->getFromDBByCrit(['forms_forms_id' => $formId])) {
    throw new RuntimeException('Coffee break form has no section');
}
$sectionId = (int) $section->getID();

$questions = [
    'reservation_id' => seed_question($sectionId, 'ID da reserva', QuestionTypeNumber::class, 0, true),
    'participants'   => seed_question($sectionId, 'Quantidade de participantes', QuestionTypeNumber::class, 1, true),
    'service_time'   => seed_question($sectionId, 'Horário desejado do Coffee Break', QuestionTypeShortText::class, 2, true),
    'service_type'   => seed_question($sectionId, 'Tipo de serviço', QuestionTypeShortText::class, 3, true),
    'restrictions'   => seed_question($sectionId, 'Restrições alimentares / observações', QuestionTypeLongText::class, 4, false),
    'cost_center'    => seed_question($sectionId, 'Centro de custo', QuestionTypeShortText::class, 5, false),
];

if ($formCreated) {
    $accessControl = new FormAccessControl();
    $accessControl->add([
        Form::getForeignKeyField() => $formId,
        'strategy'                 => AllowList::class,
        '_config'                  => new AllowListConfig(user_ids: [AbstractRightsDropdown::ALL_USERS]),
        'is_active'                => 1,
    ]);

    $destination = new FormDestination();
    if (!$destination->getFromDBByCrit(['forms_forms_id' => $formId, 'itemtype' => FormDestinationTicket::class])) {
        throw new RuntimeException('Coffee break form has no ticket destination');
    }

    $reservationTag = (new AnswerTagProvider())->getTagForQuestion(Question::getById($questions['reservation_id']));
    $updated = $destination->update([
        'id'     => $destination->getID(),
        'name'   => 'Ticket Coffee Break',
        'config' => [
            TitleField::getKey() => [
                SimpleValueConfig::VALUE => 'Coffee Break - Reserva ' . $reservationTag->html,
            ],
            ITILCategoryField::getKey() => [
                ITILCategoryFieldConfig::STRATEGY                 => ITILCategoryFieldStrategy::SPECIFIC_VALUE->value,
                ITILCategoryFieldConfig::SPECIFIC_ITILCATEGORY_ID => $categoryId,
            ],
            AssigneeField::getKey() => [
                ITILActorFieldConfig::STRATEGIES              => [ITILActorFieldStrategy::SPECIFIC_VALUES->value],
                ITILActorFieldConfig::SPECIFIC_ITILACTORS_IDS => ['groups_id-' . $groupId],
            ],
        ],
    ]);
    if (!$updated) {
        throw new RuntimeException('Unable to configure the coffee break ticket destination');
    }
}

$reservationQuestion = Question::getById($questions['reservation_id']);

Config::setConfigurationValues(PluginConfig::CONTEXT, [
    PluginConfig::KEY_RESERVABLE_ITEM_IDS     => implode(',', array_column($rooms, 'reservationitem_id')),
    PluginConfig::KEY_COFFEE_FORM_ID          => (string) $formId,
    PluginConfig::KEY_RESERVATION_QUESTION_ID => (string) $questions['reservation_id'],
]);

echo json_encode([
    'sub_entity_id'             => $subEntityId,
    'rooms'                     => $rooms,
    'non_room'                  => $nonRoom,
    'users'                     => $users,
    'group_id'                  => $groupId,
    'category_id'               => $categoryId,
    'form_id'                   => $formId,
    'section_id'                => $sectionId,
    'questions'                 => $questions,
    'reservation_question_uuid' => (string) $reservationQuestion->fields['uuid'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
