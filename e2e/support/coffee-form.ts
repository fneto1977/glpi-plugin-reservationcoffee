import type { Locator, Page } from '@playwright/test';
import { seed, type QuestionKey } from './seed';
import { sql } from './stack';
import { glpiDateTime } from './time';

/** Text or number input of a question of the coffee break form. */
export function answerInput(page: Page, key: QuestionKey): Locator {
  return page.locator(`input[name="answers_${seed().questions[key]}"]`);
}

/** Radio or checkbox of a selectable question, by option key ("sim", "cafe"...). */
export function option(page: Page, key: 'needs_coffee' | 'items', value: string): Locator {
  return page.locator(`input[name="answers_${seed().questions[key]}[]"][value="${value}"]`);
}

export function questionBlock(page: Page, key: QuestionKey): Locator {
  return page.locator(`section[data-glpi-form-renderer-question][data-glpi-form-renderer-id="${seed().questions[key]}"]`);
}

export interface CoffeeRequest {
  reservationId?: string;
  participants?: string;
  needsCoffee?: 'sim' | 'nao';
  items?: string[];
}

/** Fills the questions the user must answer and submits the form. */
export async function submitCoffeeRequest(page: Page, request: CoffeeRequest = {}): Promise<void> {
  if (request.reservationId !== undefined) {
    await answerInput(page, 'reservation_id').fill(request.reservationId);
  }
  await answerInput(page, 'participants').fill(request.participants ?? '8');
  await option(page, 'needs_coffee', request.needsCoffee ?? 'sim').check();
  if ((request.needsCoffee ?? 'sim') === 'sim') {
    for (const item of request.items ?? ['cafe']) {
      await option(page, 'items', item).check();
    }
  }
  await page.getByRole('button', { name: 'Enviar' }).click();
}

/** Ticket content as text: GLPI stores it with HTML entities. */
export function decodeHtml(html: string): string {
  return html
    .replace(/&#(\d+);/g, (_, code: string) => String.fromCodePoint(Number(code)))
    .replace(/&#x([0-9a-f]+);/gi, (_, code: string) => String.fromCodePoint(parseInt(code, 16)))
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll('&quot;', '"')
    .replaceAll('&amp;', '&');
}

export function maxTicketId(): number {
  return Number(sql('SELECT COALESCE(MAX(id), 0) AS max_id FROM glpi_tickets')[0].max_id);
}

/** Ticket title the plugin builds from the reservation as stored ("dd/mm/yyyy HH:MM", server timezone). */
export function expectedTicketTitle(roomName: string, begin: Date, reservationId: number): string {
  const [date, time] = glpiDateTime(begin).split(' ');
  const [year, month, day] = date.split('-');
  return `Coffee Break - ${roomName} - ${day}/${month}/${year} ${time.slice(0, 5)} - Reserva #${reservationId}`;
}
