<?php

namespace GlpiPlugin\Reservationcoffee\Tests\Unit;

use GlpiPlugin\Reservationcoffee\Domain\FormProvisionResult;
use GlpiPlugin\Reservationcoffee\Domain\FormProvisionStatus;
use PHPUnit\Framework\TestCase;

final class FormProvisionResultTest extends TestCase
{
    public function testCreatedResultIsSuccessful(): void
    {
        $result = new FormProvisionResult(
            FormProvisionStatus::CREATED,
            12,
            ['reservation_id' => 71, 'participants' => 72],
            ['Form successfully created.'],
        );

        self::assertTrue($result->isSuccess());
        self::assertSame(FormProvisionStatus::CREATED, $result->status);
        self::assertSame(12, $result->formId);
        self::assertSame(71, $result->questionIds['reservation_id']);
        self::assertSame(['Form successfully created.'], $result->messages);
    }

    public function testAlreadyExistsResultIsSuccessful(): void
    {
        $result = new FormProvisionResult(
            FormProvisionStatus::ALREADY_EXISTS,
            12,
            ['reservation_id' => 71],
        );

        self::assertTrue($result->isSuccess());
        self::assertSame(FormProvisionStatus::ALREADY_EXISTS, $result->status);
    }

    public function testFailedResultIsNotSuccessful(): void
    {
        $result = new FormProvisionResult(
            FormProvisionStatus::FAILED,
            0,
            [],
            ['Error during form creation.'],
        );

        self::assertFalse($result->isSuccess());
        self::assertSame(FormProvisionStatus::FAILED, $result->status);
    }
}
