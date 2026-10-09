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

use Closure;
use CommonDBTM;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\Clock;
use GlpiPlugin\Reservationcoffee\Domain\CoffeeEligibility;
use GlpiPlugin\Reservationcoffee\Domain\PendingOffer;
use GlpiPlugin\Reservationcoffee\Domain\SystemClock;
use Reservation;
use Session;
use Throwable;

/**
 * `item_add` handler for reservations. It only records a pending offer in the
 * session: it never redirects and never makes the reservation fail.
 */
final class ReservationListener
{
    /**
     * @param Closure(): bool $isInteractiveRequest
     * @param Closure(): PendingOfferStore $storeFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly ReservationResolver $resolver,
        private readonly CoffeeEligibility $eligibility,
        private readonly Clock $clock,
        private readonly PluginLogger $logger,
        private readonly Closure $isInteractiveRequest,
        private readonly Closure $storeFactory,
    ) {}

    public static function create(): self
    {
        $clock  = new SystemClock();
        $config = Config::load();

        return new self(
            $config,
            new ReservationResolver($config),
            new CoffeeEligibility($clock, Config::MIN_NOTICE_SECONDS),
            $clock,
            new PluginLogger(),
            static fn(): bool => RequestContext::isInteractiveWebRequest(),
            static fn(): PendingOfferStore => PendingOfferStore::fromSession($clock),
        );
    }

    public function afterAdd(CommonDBTM $item): void
    {
        if (!$item instanceof Reservation) {
            return;
        }

        $reservationId = (int) $item->getID();
        try {
            if ($this->config->invalidKeys !== []) {
                $this->logger->warning('item_add_reservation', PluginLogger::CATEGORY_CONFIGURATION, [
                    'reservation_id' => $reservationId,
                    'invalid_keys'   => implode(',', $this->config->invalidKeys),
                ]);
            }
            if (!$this->config->isOfferEnabled() || !($this->isInteractiveRequest)()) {
                return;
            }

            $usersId = (int) Session::getLoginUserID();
            if ($usersId <= 0) {
                return;
            }

            $context = $this->resolver->fromReservation($item);
            if ($context === null) {
                return;
            }

            $now = $this->clock->now()->getTimestamp();
            ($this->storeFactory)()->add(new PendingOffer(
                $context->reservationId,
                $usersId,
                $this->eligibility->isEligible($context->begin),
                $context->seriesKey(),
                $now,
                $now + $this->config->pendingTtlSeconds,
            ));
        } catch (Throwable $error) {
            // The reservation is the main process: report and keep it untouched.
            $this->logger->error('item_add_reservation', $error, ['reservation_id' => $reservationId]);
        }
    }
}
