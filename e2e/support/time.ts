import { loadEnv } from './env';

export const MINUTE = 60_000;
export const HOUR = 60 * MINUTE;
export const DAY = 24 * HOUR;
const SLOT = 5 * MINUTE;

/** Formats an instant as GLPI expects it ("Y-m-d H:i:s") in the server timezone. */
export function glpiDateTime(instant: Date): string {
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone: loadEnv().timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).format(instant);
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
