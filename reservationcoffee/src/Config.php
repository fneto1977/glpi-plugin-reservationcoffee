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

namespace GlpiPlugin\Reservationcoffee;

use Config as GlpiConfig;

/**
 * Plugin settings, stored in the GLPI configuration table under the
 * `plugin:reservationcoffee` context and edited with
 * `php bin/console config:set --context=plugin:reservationcoffee <key> <value>`.
 *
 * Missing or invalid values disable the feature (fail closed): reservations
 * keep their native behaviour and the coffee form is not guarded.
 */
final class Config
{
    public const CONTEXT = 'plugin:reservationcoffee';

    public const KEY_RESERVABLE_ITEM_IDS    = 'reservable_item_ids';
    public const KEY_COFFEE_FORM_ID         = 'coffee_form_id';
    public const KEY_RESERVATION_QUESTION_ID = 'reservation_question_id';
    public const KEY_PENDING_TTL_SECONDS    = 'pending_ttl_seconds';

    public const MIN_NOTICE_SECONDS = 48 * 60 * 60;

    public const DEFAULT_PENDING_TTL_SECONDS = 300;
    public const MIN_PENDING_TTL_SECONDS     = 5;
    public const MAX_PENDING_TTL_SECONDS     = 3600;

    /**
     * @param list<int> $reservableItemIds IDs of `ReservationItem` rows (not of the underlying assets)
     */
    public function __construct(
        public readonly array $reservableItemIds,
        public readonly int $coffeeFormId,
        public readonly int $reservationQuestionId,
        public readonly int $pendingTtlSeconds,
    ) {}

    public static function load(): self
    {
        return self::fromRaw(GlpiConfig::getConfigurationValues(self::CONTEXT));
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromRaw(array $raw): self
    {
        return new self(
            self::parseIdList((string) ($raw[self::KEY_RESERVABLE_ITEM_IDS] ?? '')),
            self::parsePositiveInt($raw[self::KEY_COFFEE_FORM_ID] ?? null),
            self::parsePositiveInt($raw[self::KEY_RESERVATION_QUESTION_ID] ?? null),
            self::parseTtl($raw[self::KEY_PENDING_TTL_SECONDS] ?? null),
        );
    }

    public static function installDefaults(): void
    {
        $current = GlpiConfig::getConfigurationValues(self::CONTEXT);
        $defaults = [
            self::KEY_RESERVABLE_ITEM_IDS     => '',
            self::KEY_COFFEE_FORM_ID          => '',
            self::KEY_RESERVATION_QUESTION_ID => '',
            self::KEY_PENDING_TTL_SECONDS     => (string) self::DEFAULT_PENDING_TTL_SECONDS,
        ];

        $missing = array_diff_key($defaults, $current);
        if ($missing !== []) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    public static function removeAll(): void
    {
        GlpiConfig::deleteConfigurationValues(self::CONTEXT, [
            self::KEY_RESERVABLE_ITEM_IDS,
            self::KEY_COFFEE_FORM_ID,
            self::KEY_RESERVATION_QUESTION_ID,
            self::KEY_PENDING_TTL_SECONDS,
        ]);
    }

    public function isReservableItemConfigured(int $reservationItemId): bool
    {
        return in_array($reservationItemId, $this->reservableItemIds, true);
    }

    public function isOfferEnabled(): bool
    {
        return $this->reservableItemIds !== [] && $this->coffeeFormId > 0;
    }

    public function isGuardEnabled(): bool
    {
        return $this->coffeeFormId > 0 && $this->reservationQuestionId > 0;
    }

    /**
     * @return list<int>
     */
    private static function parseIdList(string $value): array
    {
        $ids = [];
        foreach (explode(',', $value) as $part) {
            $id = self::parsePositiveInt(trim($part));
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function parsePositiveInt(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (!is_string($value) || preg_match('/^\d{1,10}$/', $value) !== 1) {
            return 0;
        }

        return (int) $value;
    }

    private static function parseTtl(mixed $value): int
    {
        $ttl = self::parsePositiveInt($value);
        if ($ttl === 0) {
            return self::DEFAULT_PENDING_TTL_SECONDS;
        }

        return min(self::MAX_PENDING_TTL_SECONDS, max(self::MIN_PENDING_TTL_SECONDS, $ttl));
    }
}
