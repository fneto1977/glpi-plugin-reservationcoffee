import { maxTicketId, submitCoffeeRequest } from '../support/coffee-form';
import { test, expect, offerCard, reserveAndExpectOffer } from '../support/fixtures';
import { seed } from '../support/seed';
import { logCursor } from '../support/stack';
import { DAY, startIn } from '../support/time';
import type { Page } from '@playwright/test';

const ROOM = () => seed().rooms[0];

function offerPath(id: number | string): string {
  return `/plugins/reservationcoffee/offer/${id}`;
}

async function submitCoffeeForm(page: Page, reservationId: string): Promise<void> {
  await submitCoffeeRequest(page, { reservationId });
}

async function openFormFromCatalog(page: Page): Promise<void> {
  await page.goto('/ServiceCatalog');
  await page.getByText('Solicitação de Coffee Break').first().click();
  await page.waitForURL((url) => url.pathname === `/Form/Render/${seed().form_id}`);
}

test.describe('Security', () => {
  test("E2E-13 another user's reservation is denied by URL", async ({ as }) => {
    const owner = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(owner, ROOM().reservationitem_id, startIn(3 * DAY));
    const log = logCursor('reservationcoffee.log');

    const intruder = await as('selfservice2');
    const response = await intruder.goto(offerPath(reservationId));

    expect(response?.status()).toBe(403);
    await expect(offerCard(intruder)).toHaveCount(0);
    await expect(intruder.getByText(ROOM().name)).toHaveCount(0);
    expect(log()).toMatch(/operation=offer_access category=AUTHORIZATION_ERROR reservation_id=\d+ users_id=\d+/);

    // The owner's offer is untouched by the attempt.
    await owner.reload();
    await expect(offerCard(owner)).toHaveAttribute('data-state', 'eligible');
  });

  test('E2E-14 unknown or malformed IDs answer 404', async ({ as }) => {
    const page = await as('selfservice');

    for (const id of ['999999', 'abc', '-1', '1e3']) {
      const response = await page.goto(offerPath(id));
      expect(response?.status(), `offer/${id}`).toBe(404);
      await expect(offerCard(page)).toHaveCount(0);
    }
  });

  test('E2E-17 POST without CSRF token is rejected and keeps the offer', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(page, ROOM().reservationitem_id, startIn(3 * DAY));

    for (const action of ['accept', 'decline']) {
      const response = await page.request.post(`${offerPath(reservationId)}/${action}`, { form: {}, maxRedirects: 0 });
      expect(response.status(), action).toBe(403);
    }
    const forged = await page.request.post(`${offerPath(reservationId)}/accept`, {
      form: { _glpi_csrf_token: 'forged-token' },
      maxRedirects: 0,
    });
    expect(forged.status()).toBe(403);

    await page.reload();
    await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');
    await page.getByTestId('reservationcoffee-accept').click();
    await page.waitForURL((url) => url.pathname === `/Form/Render/${seed().form_id}`);
  });

  test('E2E-30 GET on accept/decline is refused and keeps the offer', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(page, ROOM().reservationitem_id, startIn(3 * DAY));

    for (const action of ['accept', 'decline']) {
      const response = await page.request.get(`${offerPath(reservationId)}/${action}`, { maxRedirects: 0 });
      expect(response.status(), action).toBe(405);
      expect(response.headers()['allow'], action).toBe('POST');
    }

    await page.reload();
    await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');
    await page.getByTestId('reservationcoffee-accept').click();
    await page.waitForURL((url) => url.pathname === `/Form/Render/${seed().form_id}`);
  });

  test('E2E-21 user from another entity cannot reach the offer', async ({ as }) => {
    const owner = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(owner, ROOM().reservationitem_id, startIn(3 * DAY));

    const outsider = await as('otherentity');
    const response = await outsider.goto(offerPath(reservationId));

    expect([403, 404]).toContain(response?.status());
    await expect(offerCard(outsider)).toHaveCount(0);
    await expect(outsider.getByText(ROOM().name)).toHaveCount(0);
  });

  test.describe('E2E-23 catalog bypass is blocked server-side', () => {
    async function reservationOf(page: Page, beginInMs: number): Promise<string> {
      return String(await reserveAndExpectOffer(page, ROOM().reservationitem_id, startIn(beginInMs)));
    }

    test('reservation starting in less than 48h', async ({ as }) => {
      const page = await as('selfservice');
      const reservationId = await reservationOf(page, 1 * DAY);
      const before = maxTicketId();

      await openFormFromCatalog(page);
      await submitCoffeeForm(page, reservationId);

      await expect(page.getByText('mínimo 48 horas de antecedência do início da reunião')).toBeVisible();
      expect(maxTicketId()).toBe(before);
    });

    test("another user's reservation", async ({ as }) => {
      const other = await as('selfservice2');
      const reservationId = await reservationOf(other, 5 * DAY);
      const page = await as('selfservice');
      const before = maxTicketId();

      await openFormFromCatalog(page);
      await submitCoffeeForm(page, reservationId);

      await expect(page.getByText('não foi encontrada ou não permite')).toBeVisible();
      expect(maxTicketId()).toBe(before);
    });

    test('nonexistent reservation', async ({ as }) => {
      const page = await as('selfservice');
      const before = maxTicketId();

      await openFormFromCatalog(page);
      await submitCoffeeForm(page, '999999');

      await expect(page.getByText('não foi encontrada ou não permite')).toBeVisible();
      expect(maxTicketId()).toBe(before);
    });
  });
});
