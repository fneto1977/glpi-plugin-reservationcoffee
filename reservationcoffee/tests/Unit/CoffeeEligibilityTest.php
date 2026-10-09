<?php

namespace GlpiPlugin\Reservationcoffee\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\CoffeeEligibility;
use GlpiPlugin\Reservationcoffee\Tests\Support\FrozenClock;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoffeeEligibilityTest extends TestCase
{
    private const NOW_UTC = '2026-10-09T15:00:00+00:00';

    private function eligibility(string $now = self::NOW_UTC): CoffeeEligibility
    {
        return new CoffeeEligibility(FrozenClock::at($now), Config::MIN_NOTICE_SECONDS);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function boundaryProvider(): iterable
    {
        yield '47h59m59s is refused' => ['2026-10-11T14:59:59+00:00', false];
        yield 'exactly 48h is accepted' => ['2026-10-11T15:00:00+00:00', true];
        yield '48h and 1s is accepted' => ['2026-10-11T15:00:01+00:00', true];
        yield '30 days ahead is accepted' => ['2026-11-08T15:00:00+00:00', true];
        yield 'meeting in the past is refused' => ['2026-10-08T15:00:00+00:00', false];
        yield 'meeting starting now is refused' => ['2026-10-09T15:00:00+00:00', false];
    }

    #[DataProvider('boundaryProvider')]
    public function testBoundaries(string $start, bool $expected): void
    {
        self::assertSame($expected, $this->eligibility()->isEligible(new DateTimeImmutable($start)));
    }

    public function testTimezoneOfTheStartDateDoesNotMatter(): void
    {
        // 2026-10-11 12:00 in Sao Paulo (UTC-3) is exactly now + 48h.
        $start = new DateTimeImmutable('2026-10-11 12:00:00', new DateTimeZone('America/Sao_Paulo'));

        self::assertTrue($this->eligibility()->isEligible($start));
        self::assertFalse($this->eligibility()->isEligible($start->modify('-1 second')));
    }

    public function testHistoricalSaoPauloDstStartUsesElapsedSeconds(): void
    {
        // Brazil DST started on 2018-11-04 00:00 (clocks jumped to 01:00).
        // From 2018-11-03 12:00 (UTC-3), 48 elapsed hours lead to 2018-11-05 13:00 (UTC-2).
        $tz  = new DateTimeZone('America/Sao_Paulo');
        $now = (new DateTimeImmutable('2018-11-03 12:00:00', $tz))->format(DATE_ATOM);
        $eligibility = $this->eligibility($now);

        self::assertFalse($eligibility->isEligible(new DateTimeImmutable('2018-11-05 12:59:59', $tz)));
        self::assertTrue($eligibility->isEligible(new DateTimeImmutable('2018-11-05 13:00:00', $tz)));
    }

    public function testEuropeanDstEndUsesElapsedSeconds(): void
    {
        // Paris DST ended on 2026-10-25 03:00 -> 02:00: from 2026-10-24 10:00 (UTC+2),
        // 48 elapsed hours lead to 2026-10-26 09:00 (UTC+1), only 47 wall-clock hours later.
        $tz  = new DateTimeZone('Europe/Paris');
        $now = (new DateTimeImmutable('2026-10-24 10:00:00', $tz))->format(DATE_ATOM);
        $eligibility = $this->eligibility($now);

        self::assertFalse($eligibility->isEligible(new DateTimeImmutable('2026-10-26 08:59:59', $tz)));
        self::assertTrue($eligibility->isEligible(new DateTimeImmutable('2026-10-26 09:00:00', $tz)));
    }

    public function testParseGlpiDateTimeUsesTheGivenTimezone(): void
    {
        $parsed = CoffeeEligibility::parseGlpiDateTime('2026-10-15 14:00:00', new DateTimeZone('America/Sao_Paulo'));

        self::assertSame('2026-10-15T17:00:00+00:00', $parsed->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDateProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'date only' => ['2026-10-15'];
        yield 'garbage' => ['not a date'];
    }

    #[DataProvider('invalidDateProvider')]
    public function testParseGlpiDateTimeRejectsInvalidValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        CoffeeEligibility::parseGlpiDateTime($value, new DateTimeZone('UTC'));
    }

    public function testMinimumNoticeMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CoffeeEligibility(FrozenClock::at(self::NOW_UTC), 0);
    }
}
