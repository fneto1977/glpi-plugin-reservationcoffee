import { loadEnv } from './env';

export const MINUTE = 60_000;
export const HOUR = 60 * MINUTE;
export const DAY = 24 * HOUR;
const SLOT = 5 * MINUTE;

/** Formats an instant as GLPI expects it ("Y-m-d H:i:s"), by default in the server timezone. */
export function glpiDateTime(instant: Date, timeZone: string = loadEnv().timezone): string {
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).format(instant);
}

/** "Date HH:MM" as GLPI displays it for a date format preference (0 = Y-m-d, 1 = d-m-Y, 2 = m-d-Y). */
export function displayedDateTime(instant: Date, dateFormat: number, timeZone: string = loadEnv().timezone): string {
  const [date, time] = glpiDateTime(instant, timeZone).split(' ');
  const [year, month, day] = date.split('-');
  const shown = dateFormat === 1 ? `${day}-${month}-${year}` : dateFormat === 2 ? `${month}-${day}-${year}` : date;
  return `${shown} ${time.slice(0, 5)}`;
}

/** English weekday name ("Monday"...) of an instant in the server timezone, as used by GLPI periodicity fields. */
export function weekdayName(instant: Date): string {
  return new Intl.DateTimeFormat('en-US', { timeZone: loadEnv().timezone, weekday: 'long' }).format(instant);
}

export function slotFloor(ms: number): Date {
  return new Date(Math.floor(ms / SLOT) * SLOT);
}

export function slotCeil(ms: number): Date {
  return new Date(Math.ceil(ms / SLOT) * SLOT);
}

/** A slot-aligned start `offsetMs` from now, rounded up. */
export function startIn(offsetMs: number): Date {
  return slotCeil(Date.now() + offsetMs);
}
