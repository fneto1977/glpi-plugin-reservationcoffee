import { test, expect, offerCard, OFFER_URL, PENDING_PATH, reserve, reserveAndExpectOffer, waitForPending } from '../support/fixtures';
import { seed } from '../support/seed';
import { sql } from '../support/stack';
import { DAY, HOUR, MINUTE, glpiDateTime, slotCeil, slotFloor, startIn } from '../support/time';

const MIN_NOTICE = 48 * HOUR;

test.describe('Coffee break offer after a room reservation', () => {
  test('E2E-01 non-room item keeps the native flow', async ({ as }) => {
    const page = await as('selfservice');
    const pending = waitForPending(page);

    await reserve(page, seed().non_room.reservationitem_id, startIn(3 * DAY));

    expect((await pending).status()).toBe(204);
    await page.waitForLoadState('networkidle');
    expect(page.url()).toContain('/front/reservation.form.php');
    await expect(offerCard(page)).toHaveCount(0);
    expect(sql(`SELECT id FROM glpi_reservations WHERE reservationitems_id = ${seed().non_room.reservationitem_id}`)).toHaveLength(1);
  });

  for (const [index, room] of seed().rooms.entries()) {
    test(`E2E-0${index + 2} room "${room.name}" eligible at +72h`, async ({ as }) => {
      const page = await as('selfservice');
      const begin = startIn(3 * DAY);

      const reservationId = await reserveAndExpectOffer(page, room.reservationitem_id, begin);

      await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');
      await expect(page.getByTestId('reservationcoffee-room')).toHaveText(room.name);
      await expect(page.getByTestId('reservationcoffee-period')).toContainText(glpiDateTime(begin).slice(0, 16));
      await expect(page.getByTestId('reservationcoffee-reservation-id')).toHaveText(String(reservationId));
      await expect(page.getByText('Deseja solicitar Coffee Break?')).toBeVisible();
      await expect(page.getByTestId('reservationcoffee-decline')).toHaveText('Não, voltar ao calendário');
      await expect(page.getByTestId('reservationcoffee-accept')).toHaveText('Solicitar Coffee Break');
    });
  }

  test('E2E-06 slot just before now+48h is not eligible', async ({ as }) => {
    const page = await as('selfservice');
    const begin = slotFloor(Date.now() + MIN_NOTICE - MINUTE);

    await reserveAndExpectOffer(page, seed().rooms[0].reservationitem_id, begin);

    await expect(offerCard(page)).toHaveAttribute('data-state', 'not_eligible');
    await expect(page.getByTestId('reservationcoffee-not-eligible')).toContainText('menos de 48 horas');
    await expect(page.getByTestId('reservationcoffee-back')).toHaveText('Voltar ao calendário');
    await expect(page.getByTestId('reservationcoffee-accept')).toHaveCount(0);
    await expect(page.locator('a[href*="/Form/"]')).toHaveCount(0);
  });

  test('E2E-07 first slot at or after now+48h is eligible', async ({ as }) => {
    const page = await as('selfservice');
    const begin = slotCeil(Date.now() + MIN_NOTICE + MINUTE);

    await reserveAndExpectOffer(page, seed().rooms[1].reservationitem_id, begin);

    await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');
    await expect(page.getByTestId('reservationcoffee-accept')).toBeVisible();
  });

  test('E2E-08 start in 30 days is eligible', async ({ as }) => {
    const page = await as('selfservice');

    await reserveAndExpectOffer(page, seed().rooms[2].reservationitem_id, startIn(30 * DAY));

    await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');
  });

  test('E2E-09 "No" goes back to the calendar and clears the offer', async ({ as }) => {
    const page = await as('selfservice');
    await reserveAndExpectOffer(page, seed().rooms[0].reservationitem_id, startIn(3 * DAY));

    await page.getByTestId('reservationcoffee-decline').click();

    await page.waitForURL(/\/front\/reservationitem\.php$/);
    const pending = await page.request.get(PENDING_PATH);
    expect(pending.status()).toBe(204);
  });

  test('E2E-10 "Yes" opens the configured form with the reservation prefilled', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(page, seed().rooms[0].reservationitem_id, startIn(3 * DAY));

    await page.getByTestId('reservationcoffee-accept').click();

    await page.waitForURL((url) => url.pathname === `/Form/Render/${seed().form_id}`);
    expect(new URL(page.url()).searchParams.get(seed().reservation_question_uuid.replaceAll('.', '_'))).toBe(String(reservationId));
    await expect(page.locator(`input[name="answers_${seed().questions.reservation_id}"]`)).toHaveValue(String(reservationId));
  });

  test('E2E-11 form submission creates the Coffee Break ticket', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(page, seed().rooms[3].reservationitem_id, startIn(4 * DAY));
    await page.getByTestId('reservationcoffee-accept').click();
    await page.waitForURL(/\/Form\/Render\//);
    const before = Number(sql('SELECT COALESCE(MAX(id), 0) AS max_id FROM glpi_tickets')[0].max_id);

    const { questions } = seed();
    await page.locator(`input[name="answers_${questions.participants}"]`).fill('12');
    await page.locator(`input[name="answers_${questions.service_time}"]`).fill('10:00');
    await page.locator(`input[name="answers_${questions.service_type}"]`).fill('Coffee simples');
    await page.locator(`input[name="answers_${questions.cost_center}"]`).fill('CC-E2E');
    await page.getByRole('button', { name: 'Enviar' }).click();

    await expect
      .poll(() => sql(`SELECT id FROM glpi_tickets WHERE id > ${before}`).length, { timeout: 15_000 })
      .toBe(1);
    const ticket = sql(`SELECT id, name, itilcategories_id FROM glpi_tickets WHERE id > ${before}`)[0];
    expect(Number(ticket.itilcategories_id)).toBe(seed().category_id);
    expect(ticket.name).toContain(String(reservationId));
    const assigned = sql(`SELECT groups_id FROM glpi_groups_tickets WHERE tickets_id = ${ticket.id} AND type = 2`);
    expect(assigned.map((row) => Number(row.groups_id))).toContain(seed().group_id);
  });

  test('E2E-12 refreshing does not redirect again nor loop', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(page, seed().rooms[1].reservationitem_id, startIn(3 * DAY));

    await page.reload();
    expect(page.url()).toMatch(OFFER_URL);
    await expect(offerCard(page)).toHaveAttribute('data-state', 'eligible');

    const backPending = waitForPending(page);
    await page.goBack();
    expect((await backPending).status()).toBe(204);

    const reloadPending = waitForPending(page);
    await page.reload();
    expect((await reloadPending).status()).toBe(204);
    await page.waitForLoadState('networkidle');
    expect(page.url()).toContain('/front/reservation.form.php');
    expect(sql(`SELECT id FROM glpi_reservations WHERE id = ${reservationId}`)).toHaveLength(1);
  });
});
