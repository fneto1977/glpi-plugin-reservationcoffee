import { test as base, expect, type Browser, type Page, type Response } from '@playwright/test';
import { loadEnv, type E2EUserKey } from './env';
import { resetReservations } from './stack';
import { glpiDateTime } from './time';

export const OFFER_URL = /\/plugins\/reservationcoffee\/offer\/(\d+)$/;
export const PENDING_PATH = '/plugins/reservationcoffee/pending';

export interface ReserveOptions {
  weeklyUntil?: Date;
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

export function waitForPending(page: Page): Promise<Response> {
  return page.waitForResponse((response) => new URL(response.url()).pathname === PENDING_PATH);
}

/**
 * Creates a reservation through the native GLPI form, exactly as the
 * "Reserve" action of the reservable items list does, and waits for the
 * native redirect back to the page carrying `defaultDate`.
 */
export async function reserve(page: Page, reservationItemId: number, begin: Date, options: ReserveOptions = {}): Promise<void> {
  await openReservationForm(page, reservationItemId, begin);

  if (options.weeklyUntil) {
    await selectNativeDropdown(page, 'periodicity[type]', 'Semanal');
    await page.locator('input[name="periodicity[end]"]').waitFor({ state: 'attached' });
    await setFlatpickrValue(page, 'periodicity[end]', glpiDateTime(options.weeklyUntil).slice(0, 10));
  }

  await Promise.all([
    page.waitForURL((url) => url.searchParams.has('defaultDate') || url.searchParams.has('tab_params[defaultDate]')),
    page.locator('button[name="add"]').click(),
  ]);
}

export async function openReservationForm(page: Page, reservationItemId: number, begin: Date): Promise<void> {
  const params = new URLSearchParams();
  params.set('id', '');
  params.set(`item[${reservationItemId}]`, String(reservationItemId));
  params.set('begin', glpiDateTime(begin));
  await page.goto(`/front/reservation.form.php?${params.toString()}`);
  await expect(page.locator('button[name="add"]')).toBeVisible();
}

/** Reserves and waits for the plugin to land on the offer page; returns the reservation ID. */
export async function reserveAndExpectOffer(page: Page, reservationItemId: number, begin: Date): Promise<number> {
  await reserve(page, reservationItemId, begin);
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

export const test = base.extend<{ cleanReservations: void }>({
  cleanReservations: [
    async ({}, use) => {
      resetReservations();
      await use();
    },
    { auto: true },
  ],
});

export { expect };
