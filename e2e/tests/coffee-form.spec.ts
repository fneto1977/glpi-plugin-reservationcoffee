import { answerInput, decodeHtml, maxTicketId, option, questionBlock, submitCoffeeRequest } from '../support/coffee-form';
import { test, expect, reserveAndExpectOffer } from '../support/fixtures';
import { seed } from '../support/seed';
import { sql } from '../support/stack';
import { DAY, startIn } from '../support/time';
import type { Page } from '@playwright/test';

async function openPrefilledForm(page: Page, roomIndex: number, beginInMs: number): Promise<number> {
  const reservationId = await reserveAndExpectOffer(page, seed().rooms[roomIndex].reservationitem_id, startIn(beginInMs));
  await page.getByTestId('reservationcoffee-accept').click();
  await page.waitForURL((url) => url.pathname === `/Form/Render/${seed().form_id}`);
  return reservationId;
}

function ticketsAfter(id: number): Record<string, unknown>[] {
  return sql(`SELECT id, name, content FROM glpi_tickets WHERE id > ${id}`);
}

test.describe('Coffee break request form', () => {
  test('E2E-31 participants must be a positive whole number', async ({ as }) => {
    const page = await as('selfservice');
    await openPrefilledForm(page, 1, 5 * DAY);
    const before = maxTicketId();

    await submitCoffeeRequest(page, { participants: '' });
    await expect(page).toHaveURL(new RegExp(`/Form/Render/${seed().form_id}`));

    for (const invalid of ['-3', '2.5', '10000']) {
      await submitCoffeeRequest(page, { participants: invalid });
      await expect(questionBlock(page, 'participants').getByText('O valor deve corresponder ao formato solicitado')).toBeVisible();
    }

    await submitCoffeeRequest(page, { participants: '0' });
    await expect(page.getByText('Informe um número inteiro de participantes maior que zero.')).toBeVisible();
    expect(ticketsAfter(before)).toHaveLength(0);

    await submitCoffeeRequest(page, { participants: '7' });
    await expect.poll(() => ticketsAfter(before).length, { timeout: 15_000 }).toBe(1);
  });

  test('E2E-32 requested items are only asked when a coffee break is needed', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await openPrefilledForm(page, 2, 6 * DAY);
    const before = maxTicketId();

    await expect(questionBlock(page, 'items')).toBeVisible();
    await option(page, 'needs_coffee', 'nao').check();
    await expect(questionBlock(page, 'items')).toBeHidden();
    await option(page, 'needs_coffee', 'sim').check();
    await expect(questionBlock(page, 'items')).toBeVisible();

    await submitCoffeeRequest(page, { items: [] });
    await expect(page).toHaveURL(new RegExp(`/Form/Render/${seed().form_id}`));
    expect(ticketsAfter(before)).toHaveLength(0);

    await submitCoffeeRequest(page, { needsCoffee: 'nao' });
    await expect.poll(() => ticketsAfter(before).length, { timeout: 15_000 }).toBe(1);
    const content = decodeHtml(String(ticketsAfter(before)[0].content));
    expect(content).toContain(`Reserva #${reservationId}`);
    expect(content).toContain('Não');
    expect(content).not.toContain('Itens solicitados');
  });

  test('E2E-33 the 48h rule follows the reservation ID, not the edited date', async ({ as }) => {
    const page = await as('selfservice');
    const reservationId = await reserveAndExpectOffer(page, seed().rooms[0].reservationitem_id, startIn(1 * DAY));
    const before = maxTicketId();

    await page.goto(`/Form/Render/${seed().form_id}`);
    await answerInput(page, 'date').fill('31-12-2099');
    await answerInput(page, 'start_time').fill('10:00');
    await submitCoffeeRequest(page, { reservationId: String(reservationId) });

    await expect(page.getByText('mínimo 48 horas de antecedência do início da reunião')).toBeVisible();
    expect(ticketsAfter(before)).toHaveLength(0);
  });
});
