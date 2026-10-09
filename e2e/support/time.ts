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
