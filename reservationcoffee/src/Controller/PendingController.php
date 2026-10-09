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
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Reservationcoffee\Domain\SystemClock;
use GlpiPlugin\Reservationcoffee\Service\OfferUrls;
use GlpiPlugin\Reservationcoffee\Service\PendingOfferStore;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only endpoint polled by `pending.js` after a reservation is created.
 */
final class PendingController extends AbstractController
{
    #[Route('/pending', name: 'pending', methods: 'GET')]
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    public function __invoke(): Response
    {
        $headers = ['Cache-Control' => 'no-store'];

        $offer = PendingOfferStore::fromSession(new SystemClock())
            ->latestDeliverable((int) Session::getLoginUserID());

        if ($offer === null) {
            return new Response('', Response::HTTP_NO_CONTENT, $headers);
        }

        return new JsonResponse(
            ['redirect_url' => OfferUrls::fromGlpiConfig()->offer($offer->reservationId)],
            Response::HTTP_OK,
            $headers,
        );
    }
}
