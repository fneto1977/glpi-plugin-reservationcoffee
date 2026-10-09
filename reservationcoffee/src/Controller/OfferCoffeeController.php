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

namespace GlpiPlugin\Reservationcoffee\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use Glpi\Http\Firewall;
use Glpi\Http\RedirectResponse;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Reservationcoffee\Config;
use GlpiPlugin\Reservationcoffee\Domain\CoffeeEligibility;
use GlpiPlugin\Reservationcoffee\Domain\ReservationContext;
use GlpiPlugin\Reservationcoffee\Domain\SystemClock;
use GlpiPlugin\Reservationcoffee\Service\CoffeeFormLink;
use GlpiPlugin\Reservationcoffee\Service\OfferUrls;
use GlpiPlugin\Reservationcoffee\Service\PendingOfferStore;
use GlpiPlugin\Reservationcoffee\Service\PluginLogger;
use GlpiPlugin\Reservationcoffee\Service\ReservationResolver;
use Html;
use Session;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Intermediate step shown after a meeting room reservation. Every action
 * reloads the reservation and re-checks ownership and the 48h rule.
 */
final class OfferCoffeeController extends AbstractController
{
    private const STATE_ELIGIBLE     = 'eligible';
    private const STATE_NOT_ELIGIBLE = 'not_eligible';
    private const STATE_NO_OFFER     = 'no_offer';
    private const STATE_ERROR        = 'error';

    private readonly Config $config;
    private readonly ReservationResolver $resolver;
    private readonly CoffeeEligibility $eligibility;
    private readonly PendingOfferStore $store;
    private readonly OfferUrls $urls;
    private readonly PluginLogger $logger;

    public function __construct()
    {
        $clock = new SystemClock();

        $this->config      = Config::load();
        $this->resolver    = new ReservationResolver($this->config);
        $this->eligibility = new CoffeeEligibility($clock, Config::MIN_NOTICE_SECONDS);
        $this->store       = PendingOfferStore::fromSession($clock);
        $this->urls        = OfferUrls::fromGlpiConfig();
        $this->logger      = new PluginLogger();
    }

    #[Route('/offer/{id}', name: 'offer_show', methods: 'GET', requirements: ['id' => '\d+'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function show(int $id): Response
    {
        $usersId = (int) Session::getLoginUserID();
        $context = $this->loadAuthorizedContext($id, $usersId);

        if ($this->store->find($id, $usersId) === null) {
            return $this->renderState(self::STATE_NO_OFFER, $context);
        }
        $this->store->markDispatched($id, $usersId);

        return $this->renderState(
            $this->eligibility->isEligible($context->begin) ? self::STATE_ELIGIBLE : self::STATE_NOT_ELIGIBLE,
            $context,
        );
    }

    #[Route('/offer/{id}/accept', name: 'offer_accept', methods: 'POST', requirements: ['id' => '\d+'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function accept(int $id): Response
    {
        $usersId = (int) Session::getLoginUserID();
        $context = $this->loadAuthorizedContext($id, $usersId);

        if ($this->store->consume($id, $usersId) === null) {
            return $this->renderState(self::STATE_NO_OFFER, $context);
        }

        if (!$this->eligibility->isEligible($context->begin)) {
            return $this->renderState(self::STATE_NOT_ELIGIBLE, $context, Response::HTTP_CONFLICT);
        }

        $formUrl = (new CoffeeFormLink($this->config, $this->urls->rootDoc()))->build($id);
        if ($formUrl === null) {
            $this->logger->warning('offer_accept', PluginLogger::CATEGORY_CONFIGURATION, [
                'reservation_id' => $id,
                'form_id'        => $this->config->coffeeFormId,
            ]);
            return $this->renderState(self::STATE_ERROR, $context, Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new RedirectResponse($formUrl);
    }

    #[Route('/offer/{id}/decline', name: 'offer_decline', methods: 'POST', requirements: ['id' => '\d+'])]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function decline(int $id): Response
    {
        $usersId = (int) Session::getLoginUserID();
        $this->loadAuthorizedContext($id, $usersId);
        $this->store->consume($id, $usersId);

        return new RedirectResponse($this->urls->calendar());
    }

    private function loadAuthorizedContext(int $reservationId, int $usersId): ReservationContext
    {
        if (!$this->config->isOfferEnabled()) {
            throw new NotFoundHttpException();
        }

        $context = $this->resolver->load($reservationId);
        if ($context === null) {
            throw new NotFoundHttpException();
        }

        if (!$this->resolver->canRequestCoffee($reservationId, $usersId)) {
            $this->logger->warning('offer_access', PluginLogger::CATEGORY_AUTHORIZATION, [
                'reservation_id' => $reservationId,
                'users_id'       => $usersId,
            ]);
            throw new AccessDeniedHttpException();
        }

        return $context;
    }

    private function renderState(string $state, ReservationContext $context, int $status = Response::HTTP_OK): Response
    {
        return $this->render(
            '@reservationcoffee/offer_coffee.html.twig',
            [
                'title'          => __('Coffee break', 'reservationcoffee'),
                'menu'           => ['tools', 'reservationitem'],
                'state'          => $state,
                'reservation_id' => $context->reservationId,
                'room_name'      => $context->roomName,
                'period_begin'   => Html::convDateTime($context->rawBegin),
                'period_end'     => Html::convDateTime($context->rawEnd),
                'accept_url'     => $this->urls->accept($context->reservationId),
                'decline_url'    => $this->urls->decline($context->reservationId),
                'calendar_url'   => $this->urls->calendar(),
                'catalog_url'    => $this->urls->serviceCatalog(),
            ],
            new Response('', $status, ['Cache-Control' => 'no-store']),
        );
    }
}
