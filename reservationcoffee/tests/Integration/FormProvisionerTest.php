<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservationcoffee\Tests\Integration;

use Glpi\Form\Condition\CreationStrategy;
use Glpi\Form\Destination\FormDestination;
use Glpi\Form\Destination\FormDestinationTicket;
use Glpi\Form\Form;
use Glpi\Form\Question;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\FormProvisionStatus;
use GlpiPlugin\Reservationcoffee\Service\FormProvisioner;

final class FormProvisionerTest extends IntegrationTestCase
{
    public function testSkipsCreationWhenFormNameExists(): void
    {
        $existingFormId = $this->formId();

        $provisioner = FormProvisioner::create();
        $result = $provisioner->provisionIfNeeded();

        self::assertSame(FormProvisionStatus::ALREADY_EXISTS, $result->status);
        self::assertTrue($result->isSuccess());
        self::assertSame($existingFormId, $result->formId);
        self::assertArrayHasKey('reservation_id', $result->questionIds);
        self::assertArrayHasKey('needs_coffee', $result->questionIds);
        self::assertArrayHasKey('items', $result->questionIds);
    }

    public function testCreatesFormWhenNotFound(): void
    {
        $originalFormId = $this->formId();
        $originalConfig = Config::load();
        $form = Form::getById($originalFormId);
        self::assertInstanceOf(Form::class, $form);

        $tempBackupName = 'Solicitação de Coffee Break - TestBackup ' . uniqid();
        $form->update([
            'id'   => $originalFormId,
            'name' => $tempBackupName,
        ]);

        $newFormId = 0;
        try {
            $provisioner = FormProvisioner::create();
            $result = $provisioner->provisionIfNeeded();

            self::assertSame(FormProvisionStatus::CREATED, $result->status);
            self::assertTrue($result->isSuccess());
            $newFormId = $result->formId;
            self::assertGreaterThan(0, $newFormId);
            self::assertNotSame($originalFormId, $newFormId);

            $createdForm = Form::getById($newFormId);
            self::assertInstanceOf(Form::class, $createdForm);
            self::assertSame(FormProvisioner::DEFAULT_FORM_NAME, $createdForm->fields['name']);

            // Verify all 9 expected questions were created
            $expectedKeys = [
                'reservation_id',
                'requester',
                'date',
                'start_time',
                'room',
                'participants',
                'needs_coffee',
                'items',
                'notes',
            ];
            foreach ($expectedKeys as $key) {
                self::assertArrayHasKey($key, $result->questionIds);
                self::assertGreaterThan(0, $result->questionIds[$key]);
                $q = Question::getById($result->questionIds[$key]);
                self::assertInstanceOf(Question::class, $q);
            }

            // Verify ticket destination with condition
            $destination = new FormDestination();
            self::assertTrue(
                (bool) $destination->getFromDBByCrit([
                    'forms_forms_id' => $newFormId,
                    'itemtype'       => FormDestinationTicket::class,
                ]),
            );
            self::assertSame(CreationStrategy::CREATED_IF->value, $destination->fields['creation_strategy']);

            // Verify Config was updated
            $currentConfig = Config::load();
            self::assertSame($newFormId, $currentConfig->coffeeFormId);
            self::assertSame($result->questionIds['reservation_id'], $currentConfig->reservationQuestionId);
        } finally {
            if ($newFormId > 0) {
                $newForm = Form::getById($newFormId);
                if ($newForm instanceof Form) {
                    $newForm->delete(['id' => $newFormId], true);
                }
            }

            // Restore original form name and original config
            $form->update([
                'id'   => $originalFormId,
                'name' => FormProvisioner::DEFAULT_FORM_NAME,
            ]);

            $this->restoreConfig($originalConfig);
        }
    }
}
