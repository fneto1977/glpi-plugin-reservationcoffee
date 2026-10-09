import { test as base, expect, type Browser, type Page, type Response } from '@playwright/test';
import { loadEnv, type E2EUserKey } from './env';
import { resetReservations } from './stack';
import { glpiDateTime, weekdayName } from './time';

export const OFFER_URL = /\/plugins\/reservationcoffee\/offer\/(\d+)$/;
export const PENDING_PATH = '/plugins/reservationcoffee/pending';

export interface ReserveOptions {
  /** Repeats the reservation every week on the start weekday until this date. */
  weeklyUntil?: Date;
  /** Timezone of the logged-in user, when it differs from the server one. */
  timeZone?: string;
}

export async function login(page: Page, user: E2EUserKey): Promise<void> {
  const { login: name, password } = loadEnv().credentials[user];
  await page.goto('/');
  await page.locator('#login_name').fill(name);
  await page.locator('#login_password').fill(password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/front/login.php') && url.pathname !== '/'),
    page.locator('button[name="submit"]').click(),
  ]);
}

/** A fresh browser context, hence a fresh GLPI session, logged in as `user`. */
export async function pageAs(browser: Browser, user: E2EUserKey): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await login(page, user);
  return page;
}

/** Native redirect after a reservation: `defaultDate` since GLPI 11.0.11, `month` + `year` in 11.0.4. */
export function isReservationReturn(url: URL): boolean {
  const params = url.searchParams;
  return (
    params.has('defaultDate') ||
    params.has('tab_params[defaultDate]') ||
    (params.has('month') && params.has('year')) ||
    (params.has('tab_params[month]') && params.has('tab_params[year]'))
  );
}

export function waitForPending(page: Page): Promise<Response> {
  return page.waitForResponse((response) => new URL(response.url()).pathname === PENDING_PATH);
}

/**
 * Creates a reservation through the native GLPI form, exactly as the
 * "Reserve" action of the reservable items list does, and waits for the
 * native redirect back to the calendar page (see `isReservationReturn`).
 */
export async function reserve(page: Page, reservationItemId: number, begin: Date, options: ReserveOptions = {}): Promise<void> {
  await openReservationForm(page, reservationItemId, begin, options.timeZone);

  if (options.weeklyUntil) {
    await selectNativeDropdown(page, 'periodicity[type]', 'Semanalmente');
    await page.locator(`input[name="periodicity[days][${weekdayName(begin)}]"]`).check();
    await setFlatpickrValue(page, 'periodicity[end]', glpiDateTime(options.weeklyUntil).slice(0, 10));
  }

  await Promise.all([
    page.waitForURL(isReservationReturn),
    page.locator('button[name="add"]').click(),
  ]);
}

export async function openReservationForm(page: Page, reservationItemId: number, begin: Date, timeZone?: string): Promise<void> {
  const params = new URLSearchParams();
  params.set('id', '');
  params.set(`item[${reservationItemId}]`, String(reservationItemId));
  params.set('begin', glpiDateTime(begin, timeZone));
  await page.goto(`/front/reservation.form.php?${params.toString()}`);
  await expect(page.locator('button[name="add"]')).toBeVisible();
}

/** Reserves and waits for the plugin to land on the offer page; returns the reservation ID. */
export async function reserveAndExpectOffer(
  page: Page,
  reservationItemId: number,
  begin: Date,
  options: ReserveOptions = {},
): Promise<number> {
  await reserve(page, reservationItemId, begin, options);
  await page.waitForURL(OFFER_URL);
  return Number(OFFER_URL.exec(new URL(page.url()).pathname)?.[1]);
}

export function offerCard(page: Page) {
  return page.getByTestId('reservationcoffee-offer');
}

async function selectNativeDropdown(page: Page, name: string, label: string): Promise<void> {
  const select = page.locator(`select[name="${name}"]`);
  const id = await select.getAttribute('id');
  await page.locator(`#select2-${id}-container`).click();
  await page.locator('.select2-results__option', { hasText: label }).first().click();
}

async function setFlatpickrValue(page: Page, name: string, value: string): Promise<void> {
  await page.evaluate(
    ([fieldName, fieldValue]) => {
      type Picker = { setDate: (v: string, t: boolean) => void };
      const input = document.querySelector<HTMLInputElement & { _flatpickr?: Picker }>(`input[name="${fieldName}"]`);
      if (!input) {
        throw new Error(`missing input ${fieldName}`);
      }
      const holder = input.closest('.flatpickr') as (HTMLElement & { _flatpickr?: Picker }) | null;
      const picker = input._flatpickr ?? holder?._flatpickr;
      if (picker) {
        picker.setDate(fieldValue, true);
      } else {
        input.value = fieldValue;
      }
    },
    [name, value],
  );
}

export const test = base.extend<{ cleanReservations: void; as: (user: E2EUserKey) => Promise<Page> }>({
  cleanReservations: [
    async ({}, use) => {
      resetReservations();
      await use();
    },
    { auto: true },
  ],
  /** Logs `user` in a fresh context; contexts are closed when the test ends. */
  as: async ({ browser }, use) => {
    const pages: Page[] = [];
    await use(async (user) => {
      const page = await pageAs(browser, user);
      pages.push(page);
      return page;
    });
    await Promise.all(pages.map((page) => page.context().close()));
  },
});

export { expect };
