<?php

namespace GlpiPlugin\Reservationcoffee\Tests\Unit;

use GlpiPlugin\Reservationcoffee\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testValidValuesAreParsed(): void
    {
        $config = Config::fromRaw([
            Config::KEY_RESERVABLE_ITEM_IDS     => '12, 13,14 ,15',
            Config::KEY_COFFEE_FORM_ID          => '7',
            Config::KEY_RESERVATION_QUESTION_ID => '17',
            Config::KEY_PENDING_TTL_SECONDS     => '120',
        ]);

        self::assertSame([12, 13, 14, 15], $config->reservableItemIds);
        self::assertSame(7, $config->coffeeFormId);
        self::assertSame(17, $config->reservationQuestionId);
        self::assertSame(120, $config->pendingTtlSeconds);
        self::assertTrue($config->isOfferEnabled());
        self::assertTrue($config->isGuardEnabled());
        self::assertTrue($config->isReservableItemConfigured(14));
        self::assertFalse($config->isReservableItemConfigured(16));
    }

    public function testMissingConfigurationDisablesTheFeature(): void
    {
        $config = Config::fromRaw([]);

        self::assertSame([], $config->reservableItemIds);
        self::assertFalse($config->isOfferEnabled());
        self::assertFalse($config->isGuardEnabled());
        self::assertSame(Config::DEFAULT_PENDING_TTL_SECONDS, $config->pendingTtlSeconds);
    }

    public function testInvalidValuesAreDiscarded(): void
    {
        $config = Config::fromRaw([
            Config::KEY_RESERVABLE_ITEM_IDS     => '12,abc,-3,0,12,1e3',
            Config::KEY_COFFEE_FORM_ID          => '7; DROP TABLE',
            Config::KEY_RESERVATION_QUESTION_ID => '-1',
        ]);

        self::assertSame([12], $config->reservableItemIds);
        self::assertSame(0, $config->coffeeFormId);
        self::assertSame(0, $config->reservationQuestionId);
        self::assertFalse($config->isOfferEnabled());
    }

    public function testTtlIsClamped(): void
    {
        self::assertSame(Config::MIN_PENDING_TTL_SECONDS, Config::fromRaw([Config::KEY_PENDING_TTL_SECONDS => '1'])->pendingTtlSeconds);
        self::assertSame(Config::MAX_PENDING_TTL_SECONDS, Config::fromRaw([Config::KEY_PENDING_TTL_SECONDS => '999999'])->pendingTtlSeconds);
    }

    public function testMinimumNoticeIs48ElapsedHours(): void
    {
        self::assertSame(172800, Config::MIN_NOTICE_SECONDS);
    }
}
