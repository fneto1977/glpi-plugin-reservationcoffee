import { test, expect, offerCard, OFFER_URL, PENDING_PATH, reserve, reserveAndExpectOffer, waitForPending } from '../support/fixtures';
import { loadEnv } from '../support/env';
import { seed } from '../support/seed';
import { glpiAppliesUserTimezone, legacyApi, logCursor, setConfig, sql, withPluginConfig } from '../support/stack';
import { DAY, HOUR, MINUTE, glpiDateTime, slotCeil, slotFloor, startIn } from '../support/time';

const MIN_NOTICE = 48 * HOUR;
const ROOM = () => seed().rooms[0];

/** Every `bin/console` call writes a "Test logger" line to php-errors.log. */
function withoutConsoleNoise(log: string): string {
  return log
    .split('\n')
    .filter((line) => line.trim() !== '' && !line.includes('*** Test logger'))
    .join('\n');
}

test.describe('Robustness', () => {
  test('E2E-15 an expired pending offer is not delivered', async ({ as }) => {
    await withPluginConfig({ pending_ttl_seconds: '5' }, async () => {
      const page = await as('selfservice');
      // Hold the post-reservation lookup back until the offer has expired.
      await page.route(`**${PENDING_PATH}`, (route) => route.abort());
      await reserve(page, ROOM().reservationitem_id, startIn(3 * DAY));
      const reservationId = sql('SELECT MAX(id) AS id FROM glpi_reservations')[0].id;
      await page.waitForTimeout(6_000);
      await page.unroute(`**${PENDING_PATH}`);

      const pending = waitForPending(page);
      await page.reload();
      expect((await pending).status()).toBe(204);
      await page.waitForLoadState('networkidle');
      expect(page.url()).not.toMatch(OFFER_URL);

      await page.goto(`/plugins/reservationcoffee/offer/${reservationId}`);
      await expect(offerCard(page)).toHaveAttribute('data-state', 'no_offer');
      await expect(page.getByTestId('reservationcoffee-accept')).toHaveCount(0);
    });
  });

  test('E2E-16 accept re-checks the 48h rule against the stored reservation', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(page, ROOM().reservationitem_id, startIn(3 * DAY));
    await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');

    sql(`UPDATE glpi_reservations SET begin = begin - INTERVAL 2 DAY, end = end - INTERVAL 2 DAY WHERE id = ${reservationId}`);
    const accept = page.waitForResponse((r) => r.url().endsWith(`/offer/${reservationId}/accept`));
    await page.getByTestId('reservationcoffee-accept').click();

    expect((await accept).status()).toBe(409);
    await expect(offerCard(page)).toHaveAttribute('data-state', 'not_eligible');
    expect(page.url()).not.toContain('/Form/Render/');
  });

  test('E2E-18 reservation created through the API has no side effect', async ({ as }) => {
    const errors = logCursor('php-errors.log');
    const pluginLog = logCursor('reservationcoffee.log');
    const { login, password } = loadEnv().credentials.selfservice;
    setConfig('core', 'enable_api', '1');
    setConfig('core', 'enable_api_login_credentials', '1');
    try {
      const auth = Buffer.from(`${login}:${password}`).toString('base64');
      const session = legacyApi('GET', 'initSession', { Authorization: `Basic ${auth}` });
      expect(session.status).toBe(200);
      const token = (session.body as { session_token: string }).session_token;

      const begin = startIn(3 * DAY);
      const created = legacyApi(
        'POST',
        'Reservation',
        { 'Session-Token': token },
        {
          input: {
            reservationitems_id: ROOM().reservationitem_id,
            begin: glpiDateTime(begin),
            end: glpiDateTime(new Date(begin.getTime() + HOUR)),
            users_id: seed().users.selfservice,
          },
        },
      );
      legacyApi('GET', 'killSession', { 'Session-Token': token });

      expect(created.status).toBe(201);
      const reservationId = (created.body as { id: number }).id;
      expect(sql(`SELECT id FROM glpi_reservations WHERE id = ${reservationId}`)).toHaveLength(1);
    } finally {
      setConfig('core', 'enable_api', '0');
      setConfig('core', 'enable_api_login_credentials', '0');
    }
    expect(withoutConsoleNoise(errors())).toBe('');
    expect(pluginLog()).toBe('');

    const page = await as('selfservice');
    expect((await page.request.get(PENDING_PATH)).status()).toBe(204);
  });

  test('E2E-19 missing coffee form: reservation kept, error logged, non-blocking message', async ({ as }) => {
    await withPluginConfig({ coffee_form_id: '999999' }, async () => {
      const log = logCursor('reservationcoffee.log');
      const page = await as('selfservice');
      const reservationId = await reserveAndExpectOffer(page, ROOM().reservationitem_id, startIn(3 * DAY));

      const accept = page.waitForResponse((r) => r.url().endsWith(`/offer/${reservationId}/accept`));
      await page.getByTestId('reservationcoffee-accept').click();

      expect((await accept).status()).toBe(503);
      await expect(offerCard(page)).toHaveAttribute('data-state', 'error');
      await expect(page.getByTestId('reservationcoffee-error')).toContainText('Sua reserva está confirmada');
      await expect(page.getByTestId('reservationcoffee-catalog')).toBeVisible();
      expect(sql(`SELECT id FROM glpi_reservations WHERE id = ${reservationId}`)).toHaveLength(1);
      expect(log()).toMatch(/operation=offer_accept category=CONFIGURATION_ERROR reservation_id=\d+ form_id=999999/);
    });
  });

  test('E2E-19 malformed settings disable the feature without breaking reservations', async ({ as }) => {
    await withPluginConfig({ reservable_item_ids: '1,abc' }, async () => {
      const log = logCursor('reservationcoffee.log');
      const page = await as('selfservice');
      const pending = waitForPending(page);

      await reserve(page, ROOM().reservationitem_id, startIn(3 * DAY));

      expect((await pending).status()).toBe(204);
      await page.waitForLoadState('networkidle');
      expect(page.url()).not.toMatch(OFFER_URL);
      expect(sql(`SELECT id FROM glpi_reservations WHERE reservationitems_id = ${ROOM().reservationitem_id}`)).toHaveLength(1);
      expect(log()).toMatch(/operation=item_add_reservation category=CONFIGURATION_ERROR reservation_id=\d+ invalid_keys=reservable_item_ids/);
    });
  });

  test.describe('E2E-20 user timezone different from the server', () => {
    const userTimeZone = 'Asia/Tokyo';
    const userId = () => seed().users.selfservice2;

    test.beforeEach(() => {
      test.skip(!glpiAppliesUserTimezone(), 'core bug in this GLPI version: the user timezone is ignored after login');
      sql(`UPDATE glpi_users SET timezone = '${userTimeZone}' WHERE id = ${userId()}`);
    });
    test.afterEach(() => {
      sql(`UPDATE glpi_users SET timezone = NULL WHERE id = ${userId()}`);
    });

    test('period shown in the user timezone and eligible above 48h', async ({ as }) => {
      const page = await as('selfservice2');
      const begin = slotCeil(Date.now() + MIN_NOTICE + MINUTE);

      const reservationId = await reserveAndExpectOffer(page, ROOM().reservationitem_id, begin, { timeZone: userTimeZone });

      await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');
      await expect(page.getByTestId('reservationcoffee-period')).toContainText(glpiDateTime(begin, userTimeZone).slice(0, 16));
      const stored = sql(`SELECT UNIX_TIMESTAMP(begin) AS ts FROM glpi_reservations WHERE id = ${reservationId}`)[0].ts;
      expect(Number(stored) * 1000).toBe(begin.getTime());
    });

    test('not eligible just below 48h', async ({ as }) => {
      const page = await as('selfservice2');
      const begin = slotFloor(Date.now() + MIN_NOTICE - MINUTE);

      await reserveAndExpectOffer(page, ROOM().reservationitem_id, begin, { timeZone: userTimeZone });

      await expect(offerCard(page)).toHaveAttribute('data-state', 'not_eligible');
    });
  });

  test('E2E-22 two tabs keep their own offers', async ({ as }) => {
    const first = await as('selfservice');
    const second = await first.context().newPage();

    const firstId = await reserveAndExpectOffer(first, seed().rooms[0].reservationitem_id, startIn(3 * DAY));
    const secondId = await reserveAndExpectOffer(second, seed().rooms[1].reservationitem_id, startIn(4 * DAY));
    expect(secondId).not.toBe(firstId);
    await expect(first.getByTestId('reservationcoffee-reservation-id')).toHaveText(String(firstId));
    await expect(second.getByTestId('reservationcoffee-reservation-id')).toHaveText(String(secondId));

    await second.getByTestId('reservationcoffee-decline').click();
    await second.waitForURL(/\/front\/reservationitem\.php$/);

    await first.getByTestId('reservationcoffee-accept').click();
    await first.waitForURL((url) => url.pathname === `/Form/Render/${seed().form_id}`);
    await expect(first.locator(`input[name="answers_${seed().questions.reservation_id}"]`)).toHaveValue(String(firstId));
  });
});
