import type { Page } from '@playwright/test';
import { test, expect, offerCard, OFFER_URL, PENDING_PATH, reserve, reserveAndExpectOffer, waitForPending } from '../support/fixtures';
import { seed } from '../support/seed';
import { glpiConsole, sql } from '../support/stack';
import { DAY, startIn } from '../support/time';

const ROOM = () => seed().rooms[0];

function trackPluginRequests(page: Page): string[] {
  const urls: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('/plugins/reservationcoffee/')) {
      urls.push(request.url());
    }
  });
  return urls;
}

function trackPageErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  return errors;
}

test.describe('Regression of native reservation features', () => {
  test('E2E-24 weekly series creates every occurrence and a single offer', async ({ as }) => {
    const page = await as('selfservice');
    const begin = startIn(3 * DAY);

    const offerId = await reserveAndExpectOffer(page, ROOM().reservationitem_id, begin, {
      weeklyUntil: new Date(begin.getTime() + 15 * DAY),
    });

    const occurrences = sql(
      `SELECT id, \`group\`, UNIX_TIMESTAMP(begin) AS ts FROM glpi_reservations WHERE reservationitems_id = ${ROOM().reservationitem_id} ORDER BY begin`,
    );
    expect(occurrences).toHaveLength(3);
    expect(new Set(occurrences.map((row) => row.group)).size).toBe(1);
    expect(Number(occurrences[0].group)).toBeGreaterThan(0);
    expect(offerId).toBe(Number(occurrences[0].id));
    expect(Number(occurrences[0].ts) * 1000).toBe(begin.getTime());
    await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');

    await page.getByTestId('reservationcoffee-decline').click();
    await page.waitForURL(/\/front\/reservationitem\.php$/);
    expect((await page.request.get(PENDING_PATH)).status()).toBe(204);
  });

  test('E2E-25 editing and deleting a reservation never offers coffee', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(page, ROOM().reservationitem_id, startIn(3 * DAY));
    await page.getByTestId('reservationcoffee-decline').click();
    await page.waitForURL(/\/front\/reservationitem\.php$/);

    await page.goto(`/front/reservation.form.php?id=${reservationId}`);
    let pending = waitForPending(page);
    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('tab_params[defaultDate]') || url.searchParams.has('defaultDate')),
      page.locator('button[name="update"]').click(),
    ]);
    expect((await pending).status()).toBe(204);
    await page.waitForLoadState('networkidle');
    expect(page.url()).not.toMatch(OFFER_URL);
    expect(sql(`SELECT id FROM glpi_reservations WHERE id = ${reservationId}`)).toHaveLength(1);

    await page.goto(`/front/reservation.form.php?id=${reservationId}`);
    page.once('dialog', (dialog) => dialog.accept());
    pending = waitForPending(page);
    await Promise.all([
      page.waitForURL((url) => url.searchParams.has('tab_params[defaultDate]') || url.searchParams.has('defaultDate')),
      page.locator('button[name="purge"]').click(),
    ]);
    expect((await pending).status()).toBe(204);
    await page.waitForLoadState('networkidle');
    expect(page.url()).not.toMatch(OFFER_URL);
    expect(sql(`SELECT id FROM glpi_reservations WHERE id = ${reservationId}`)).toHaveLength(0);
  });

  for (const user of ['selfservice', 'tech'] as const) {
    test(`E2E-26 calendar and free item search keep working (${user})`, async ({ as }) => {
      const page = await as(user);
      const pluginRequests = trackPluginRequests(page);
      const pageErrors = trackPageErrors(page);

      await page.goto('/front/reservationitem.php');
      for (const room of seed().rooms) {
        await expect(page.getByText(room.name).first()).toBeVisible();
      }

      await page.locator('#makesearch button').click();
      const search = page.locator('#viewresasearch');
      await expect(search).toBeVisible();
      await search.locator('button[type="submit"], input[type="submit"]').first().click();
      await page.waitForLoadState('networkidle');
      await expect(page.getByText(ROOM().name).first()).toBeVisible();

      await page.goto('/front/reservation.php?reservationitems_id=0');
      await expect(page.locator('.fc').first()).toBeVisible();
      await page.goto(`/front/reservation.php?reservationitems_id=${ROOM().reservationitem_id}`);
      await expect(page.locator('.fc').first()).toBeVisible();
      await page.waitForLoadState('networkidle');

      expect(pageErrors).toEqual([]);
      expect(pluginRequests.filter((url) => url.includes(PENDING_PATH))).toEqual([]);
    });
  }

  test('E2E-27 deactivated plugin leaves reservations 100% native', async ({ as }) => {
    glpiConsole('plugin:deactivate', 'reservationcoffee');
    try {
      const page = await as('selfservice');
      const pluginRequests = trackPluginRequests(page);

      await reserve(page, ROOM().reservationitem_id, startIn(3 * DAY));
      await page.waitForLoadState('networkidle');

      expect(page.url()).toContain('/front/reservation.form.php');
      expect(pluginRequests).toEqual([]);
      expect(await page.locator('script[src*="/plugins/reservationcoffee/"]').count()).toBe(0);
      const reservationId = sql('SELECT MAX(id) AS id FROM glpi_reservations')[0].id;
      expect(reservationId).not.toBeNull();
      expect((await page.goto(`/plugins/reservationcoffee/offer/${reservationId}`))?.status()).toBe(404);
    } finally {
      glpiConsole('plugin:activate', 'reservationcoffee');
    }
  });
});
