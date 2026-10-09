<?php

namespace GlpiPlugin\Reservationcoffee\Tests\Support;

use DateTimeImmutable;
use GlpiPlugin\Reservationcoffee\Domain\Clock;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $datetime): self
    {
        return new self(new DateTimeImmutable($datetime));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
