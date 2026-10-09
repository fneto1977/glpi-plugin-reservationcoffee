<?php

namespace GlpiPlugin\Reservationcoffee\Tests\Unit;

use GlpiPlugin\Reservationcoffee\Config;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testMissingConfigurationIsNotReportedAsInvalid(): void
    {
        $config = Config::fromRaw([
            Config::KEY_RESERVABLE_ITEM_IDS     => '',
            Config::KEY_COFFEE_FORM_ID          => ' ',
            Config::KEY_RESERVATION_QUESTION_ID => null,
            Config::KEY_PENDING_TTL_SECONDS     => '',
        ]);

        self::assertSame([], $config->invalidKeys);
    }

    public function testInvalidValuesDisableTheFeatureAndAreReported(): void
    {
        $config = Config::fromRaw([
            Config::KEY_RESERVABLE_ITEM_IDS     => '12,abc',
            Config::KEY_COFFEE_FORM_ID          => '7; DROP TABLE',
            Config::KEY_RESERVATION_QUESTION_ID => '-1',
            Config::KEY_PENDING_TTL_SECONDS     => 'soon',
        ]);

        self::assertSame([], $config->reservableItemIds);
        self::assertSame(0, $config->coffeeFormId);
        self::assertSame(0, $config->reservationQuestionId);
        self::assertSame(Config::DEFAULT_PENDING_TTL_SECONDS, $config->pendingTtlSeconds);
        self::assertFalse($config->isOfferEnabled());
        self::assertFalse($config->isGuardEnabled());
        self::assertSame(
            [
                Config::KEY_RESERVABLE_ITEM_IDS,
                Config::KEY_COFFEE_FORM_ID,
                Config::KEY_RESERVATION_QUESTION_ID,
                Config::KEY_PENDING_TTL_SECONDS,
            ],
            $config->invalidKeys,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedItemListProvider(): iterable
    {
        yield 'text' => ['12,abc'];
        yield 'negative' => ['12,-3'];
        yield 'zero' => ['0,12'];
        yield 'exponent' => ['1e3'];
        yield 'empty entry' => ['12,,13'];
        yield 'trailing comma' => ['12,'];
        yield 'leading zero' => ['012'];
        yield 'too long' => ['12345678901'];
    }

    #[DataProvider('malformedItemListProvider')]
    public function testAnyMalformedEntryDisablesTheWholeItemList(string $raw): void
    {
        $config = Config::fromRaw([
            Config::KEY_RESERVABLE_ITEM_IDS => $raw,
            Config::KEY_COFFEE_FORM_ID      => '7',
        ]);

        self::assertSame([], $config->reservableItemIds);
        self::assertFalse($config->isOfferEnabled());
        self::assertSame([Config::KEY_RESERVABLE_ITEM_IDS], $config->invalidKeys);
    }

    public function testDuplicatedItemsAreCollapsed(): void
    {
        self::assertSame([12, 13], Config::fromRaw([Config::KEY_RESERVABLE_ITEM_IDS => '12,13,12'])->reservableItemIds);
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
