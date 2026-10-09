import type { Page } from '@playwright/test';
import { test, expect, offerCard, OFFER_URL, PENDING_PATH, reserve } from '../support/fixtures';
import { seed } from '../support/seed';
import { DAY, glpiDateTime, startIn } from '../support/time';

const PENDING_ROUTE = `**${PENDING_PATH}`;

/**
 * Creates a reservation while `/pending` is unreachable, so the offer stays
 * pending whatever return URL the running GLPI version uses.
 */
async function reserveKeepingOfferPending(page: Page, reservationItemId: number, begin: Date): Promise<void> {
  await page.route(PENDING_ROUTE, (route) => route.abort());
  await reserve(page, reservationItemId, begin);
  await page.waitForLoadState('networkidle');
  await page.unroute(PENDING_ROUTE);
  expect(page.url()).not.toMatch(OFFER_URL);
}

function trackPendingRequests(page: Page): string[] {
  const urls: string[] = [];
  page.on('request', (request) => {
    if (new URL(request.url()).pathname === PENDING_PATH) {
      urls.push(request.url());
    }
  });
  return urls;
}

function returnFormats(begin: Date): Array<[string, Record<string, string>]> {
  const [year, month, day] = glpiDateTime(begin).slice(0, 10).split('-');
  return [
    ['defaultDate (GLPI 11.0.11)', { defaultDate: `${year}-${month}-${day}` }],
    ['tab_params[defaultDate] (GLPI 11.0.11)', { 'tab_params[defaultDate]': `${year}-${month}-${day}` }],
    ['month + year (GLPI 11.0.4)', { month, year }],
    ['tab_params[month] + tab_params[year] (GLPI 11.0.4)', { 'tab_params[month]': month, 'tab_params[year]': year }],
  ];
}

test.describe('Reservation return URL of every supported GLPI version', () => {
  for (const [index, [label]] of returnFormats(new Date()).entries()) {
    test(`E2E-28 ${label} return URL delivers the pending offer`, async ({ as }) => {
      const page = await as('selfservice');
      const room = seed().rooms[index];
      const begin = startIn(3 * DAY);
      await reserveKeepingOfferPending(page, room.reservationitem_id, begin);

      const query = new URLSearchParams({ reservationitems_id: String(room.reservationitem_id), ...returnFormats(begin)[index][1] });
      await page.goto(`/front/reservation.php?${query.toString()}`);

      await page.waitForURL(OFFER_URL);
      await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');
      await expect(page.getByTestId('reservationcoffee-room')).toHaveText(room.name);
    });
  }

  test('E2E-29 partial or unrelated parameters never poll the pending offer', async ({ as }) => {
    const page = await as('selfservice');
    const room = seed().rooms[0];
    const begin = startIn(3 * DAY);
    await reserveKeepingOfferPending(page, room.reservationitem_id, begin);
    const pendingRequests = trackPendingRequests(page);
    const [year, month] = glpiDateTime(begin).slice(0, 7).split('-');

    for (const query of [
      `reservationitems_id=${room.reservationitem_id}`,
      `reservationitems_id=${room.reservationitem_id}&month=${month}`,
      `reservationitems_id=${room.reservationitem_id}&year=${year}`,
      `reservationitems_id=${room.reservationitem_id}&tab_params[month]=${month}`,
    ]) {
      await page.goto(`/front/reservation.php?${query}`);
      await expect(page.locator('.fc').first()).toBeVisible();
      await page.waitForLoadState('networkidle');
      expect(page.url()).not.toMatch(OFFER_URL);
    }
    expect(pendingRequests).toEqual([]);

    await page.goto(`/front/reservation.php?reservationitems_id=${room.reservationitem_id}&month=${month}&year=${year}`);
    await page.waitForURL(OFFER_URL);
    expect(pendingRequests).toHaveLength(1);
  });
});
