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
    public const KEY_REQUESTER_QUESTION_ID  = 'requester_question_id';
    public const KEY_DATE_QUESTION_ID       = 'date_question_id';
    public const KEY_TIME_QUESTION_ID       = 'time_question_id';
    public const KEY_ROOM_QUESTION_ID       = 'room_question_id';
    public const KEY_PARTICIPANTS_QUESTION_ID = 'participants_question_id';

    /** Optional questions of the coffee form pre-filled from the reservation and the logged-in user. */
    public const PREFILL_KEYS = [
        self::KEY_REQUESTER_QUESTION_ID,
        self::KEY_DATE_QUESTION_ID,
        self::KEY_TIME_QUESTION_ID,
        self::KEY_ROOM_QUESTION_ID,
    ];

    public const MIN_NOTICE_SECONDS = 48 * 60 * 60;

    public const DEFAULT_PENDING_TTL_SECONDS = 300;
    public const MIN_PENDING_TTL_SECONDS     = 5;
    public const MAX_PENDING_TTL_SECONDS     = 3600;

    /**
     * @param list<int>    $reservableItemIds IDs of `ReservationItem` rows (not of the underlying assets)
     * @param list<string> $invalidKeys       keys holding a value that could not be parsed
     * @param array<string, int> $prefillQuestionIds question ID per `PREFILL_KEYS` entry, set ones only
     * @param int          $participantsQuestionId question whose answer must be a positive integer, 0 when unchecked
     */
    public function __construct(
        public readonly array $reservableItemIds,
        public readonly int $coffeeFormId,
        public readonly int $reservationQuestionId,
        public readonly int $pendingTtlSeconds,
        public readonly array $invalidKeys = [],
        public readonly array $prefillQuestionIds = [],
        public readonly int $participantsQuestionId = 0,
    ) {}

    public static function load(): self
    {
        return self::fromRaw(GlpiConfig::getConfigurationValues(self::CONTEXT));
    }

    /**
     * Invalid values never enable anything: a malformed item list or ID
     * disables the related behaviour as a whole (fail closed).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromRaw(array $raw): self
    {
        $invalid = [];

        $itemIds = self::parseIdList($raw[self::KEY_RESERVABLE_ITEM_IDS] ?? null);
        if ($itemIds === null) {
            $invalid[] = self::KEY_RESERVABLE_ITEM_IDS;
        }
        $formId = self::parseOptionalId($raw[self::KEY_COFFEE_FORM_ID] ?? null);
        if ($formId === null) {
            $invalid[] = self::KEY_COFFEE_FORM_ID;
        }
        $questionId = self::parseOptionalId($raw[self::KEY_RESERVATION_QUESTION_ID] ?? null);
        if ($questionId === null) {
            $invalid[] = self::KEY_RESERVATION_QUESTION_ID;
        }
        $ttl = self::parseTtl($raw[self::KEY_PENDING_TTL_SECONDS] ?? null);
        if ($ttl === null) {
            $invalid[] = self::KEY_PENDING_TTL_SECONDS;
        }
        $prefill = [];
        foreach (self::PREFILL_KEYS as $key) {
            $prefillId = self::parseOptionalId($raw[$key] ?? null);
            if ($prefillId === null) {
                $invalid[] = $key;
            } elseif ($prefillId > 0) {
                $prefill[$key] = $prefillId;
            }
        }
        $participantsId = self::parseOptionalId($raw[self::KEY_PARTICIPANTS_QUESTION_ID] ?? null);
        if ($participantsId === null) {
            $invalid[] = self::KEY_PARTICIPANTS_QUESTION_ID;
        }

        return new self(
            $itemIds ?? [],
            $formId ?? 0,
            $questionId ?? 0,
            $ttl ?? self::DEFAULT_PENDING_TTL_SECONDS,
            $invalid,
            $prefill,
            $participantsId ?? 0,
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
            self::KEY_PARTICIPANTS_QUESTION_ID => '',
        ] + array_fill_keys(self::PREFILL_KEYS, '');

        $missing = array_diff_key($defaults, $current);
        if ($missing !== []) {
            GlpiConfig::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /**
     * @param array<string, string> $values
     */
    public static function save(array $values): void
    {
        GlpiConfig::setConfigurationValues(self::CONTEXT, $values);
    }

    public static function removeAll(): void
    {
        GlpiConfig::deleteConfigurationValues(self::CONTEXT, [
            self::KEY_RESERVABLE_ITEM_IDS,
            self::KEY_COFFEE_FORM_ID,
            self::KEY_RESERVATION_QUESTION_ID,
            self::KEY_PENDING_TTL_SECONDS,
            self::KEY_PARTICIPANTS_QUESTION_ID,
            ...self::PREFILL_KEYS,
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
     * @return list<int>|null empty list when unset, null when malformed
     */
    private static function parseIdList(mixed $value): ?array
    {
        if (self::isUnset($value)) {
            return [];
        }
        if (!is_string($value)) {
            return null;
        }

        $ids = [];
        foreach (explode(',', $value) as $part) {
            $id = self::parsePositiveInt(trim($part));
            if ($id === null) {
                return null;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int|null 0 when unset, null when malformed
     */
    private static function parseOptionalId(mixed $value): ?int
    {
        return self::isUnset($value) ? 0 : self::parsePositiveInt($value);
    }

    /**
     * @return int|null clamped TTL, the default when unset, null when malformed
     */
    private static function parseTtl(mixed $value): ?int
    {
        if (self::isUnset($value)) {
            return self::DEFAULT_PENDING_TTL_SECONDS;
        }
        $ttl = self::parsePositiveInt($value);

        return $ttl === null ? null : min(self::MAX_PENDING_TTL_SECONDS, max(self::MIN_PENDING_TTL_SECONDS, $ttl));
    }

    private static function isUnset(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9]\d{0,9}$/', trim($value)) !== 1) {
            return null;
        }

        return (int) trim($value);
    }
}
