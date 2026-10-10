import { answerInput, decodeHtml, expectedTicketTitle, maxTicketId, option, submitCoffeeRequest } from '../support/coffee-form';
import { test, expect, offerCard, OFFER_URL, PENDING_PATH, reserve, reserveAndExpectOffer, waitForPending } from '../support/fixtures';
import { seed } from '../support/seed';
import { glpiDateFormat, sql } from '../support/stack';
import { DAY, HOUR, MINUTE, displayedDateTime, slotCeil, slotFloor, startIn } from '../support/time';

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
      await expect(page.getByTestId('reservationcoffee-period')).toContainText(displayedDateTime(begin, glpiDateFormat()));
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

  test('E2E-10 "Yes" opens the configured form prefilled from the reservation', async ({ as }) => {
    const page = await as('selfservice');
    const room = seed().rooms[0];
    const begin = startIn(3 * DAY);
    const reservationId = await reserveAndExpectOffer(page, room.reservationitem_id, begin);

    await page.getByTestId('reservationcoffee-accept').click();

    await page.waitForURL((url) => url.pathname === `/Form/Render/${seed().form_id}`);
    expect(new URL(page.url()).searchParams.get(seed().reservation_question_uuid.replaceAll('.', '_'))).toBe(String(reservationId));
    const [date, time] = displayedDateTime(begin, glpiDateFormat()).split(' ');
    const { realname } = sql("SELECT realname FROM glpi_users WHERE name = 'e2e.selfservice'")[0];
    await expect(answerInput(page, 'reservation_id')).toHaveValue(String(reservationId));
    await expect(answerInput(page, 'requester')).toHaveValue(new RegExp(String(realname).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
    await expect(answerInput(page, 'date')).toHaveValue(date);
    await expect(answerInput(page, 'start_time')).toHaveValue(time);
    await expect(answerInput(page, 'room')).toHaveValue(room.name);
    await expect(answerInput(page, 'participants')).toHaveValue('');
    await expect(option(page, 'needs_coffee', 'sim')).toBeChecked();

    for (const key of ['requester', 'date', 'start_time', 'room'] as const) {
      await expect(answerInput(page, key)).toBeEditable();
    }
  });

  test('E2E-11 form submission creates the Coffee Break ticket from the reservation as booked', async ({ as }) => {
    const page = await as('selfservice');
    const room = seed().rooms[3];
    const begin = startIn(4 * DAY);
    const reservationId = await reserveAndExpectOffer(page, room.reservationitem_id, begin);
    await page.getByTestId('reservationcoffee-accept').click();
    await page.waitForURL(/\/Form\/Render\//);
    const before = maxTicketId();

    await answerInput(page, 'date').fill('31-12-2099');
    await answerInput(page, 'room').fill('Sala digitada E2E');
    await submitCoffeeRequest(page, { participants: '12', items: ['cafe', 'salgados'] });

    await expect.poll(() => sql(`SELECT id FROM glpi_tickets WHERE id > ${before}`).length, { timeout: 15_000 }).toBe(1);
    const ticket = sql(`SELECT id, name, content, itilcategories_id FROM glpi_tickets WHERE id > ${before}`)[0];
    expect(ticket.name).toBe(expectedTicketTitle(room.name, begin, reservationId));
    expect(Number(ticket.itilcategories_id)).toBe(seed().category_id);
    const content = decodeHtml(String(ticket.content));
    expect(content).toContain(`Reserva #${reservationId}, conforme registrada`);
    expect(content).toContain(room.name);
    expect(content).toContain('Participantes');
    expect(content).toContain('12');
    expect(content).toContain('Café, Salgados');
    expect(content).toContain('Sala digitada E2E');
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
