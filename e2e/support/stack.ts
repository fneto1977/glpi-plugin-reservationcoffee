import { execFileSync } from 'node:child_process';
import * as path from 'node:path';
import { loadEnv } from './env';

const env = loadEnv();
const composeArgs = ['compose', '--env-file', path.join(env.repoRoot, '.env'), '-f', path.join(env.repoRoot, 'docker', 'compose.e2e.yml')];

function docker(args: string[], input?: string): string {
  return execFileSync('docker', [...composeArgs, ...args], {
    cwd: env.repoRoot,
    input,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
}

/** Runs a SQL statement against the E2E database and returns rows as objects. */
export function sql<T extends Record<string, string | null> = Record<string, string | null>>(query: string): T[] {
  const output = docker(
    ['exec', '-T', '-e', `MYSQL_PWD=${process.env.E2E_DB_PASSWORD ?? ''}`, 'db', 'mariadb', '-uglpi', 'glpi', '--batch', '--raw'],
    query,
  );
  const lines = output.split('\n').filter((line) => line !== '');
  if (lines.length === 0) {
    return [];
  }
  const header = lines[0].split('\t');
  return lines.slice(1).map((line) => {
    const cells = line.split('\t');
    return Object.fromEntries(header.map((name, i) => [name, cells[i] === 'NULL' ? null : cells[i]])) as T;
  });
}

export function glpiConsole(...args: string[]): string {
  return docker(['exec', '-T', '-u', 'www-data', 'glpi', 'bin/console', ...args]);
}

export function getConfig(context: string, key: string): string | null {
  const rows = sql(`SELECT value FROM glpi_configs WHERE context = '${context}' AND name = '${key}'`);
  return rows[0]?.value ?? null;
}

export function setConfig(context: string, key: string, value: string): void {
  glpiConsole('config:set', `--context=${context}`, key, value);
}

/** Overrides plugin settings for the duration of `body`, restoring them afterwards. */
export async function withPluginConfig<T>(values: Record<string, string>, body: () => Promise<T>): Promise<T> {
  const context = 'plugin:reservationcoffee';
  const previous = Object.fromEntries(Object.keys(values).map((key) => [key, getConfig(context, key) ?? '']));
  try {
    for (const [key, value] of Object.entries(values)) {
      setConfig(context, key, value);
    }
    return await body();
  } finally {
    for (const [key, value] of Object.entries(previous)) {
      setConfig(context, key, value);
    }
  }
}

function readLog(name: string): string {
  return docker(['exec', '-T', 'glpi', 'sh', '-c', `cat /var/glpi/logs/${name} 2>/dev/null || true`]);
}

/** Returns a function giving what was appended to a GLPI log file since this call. */
export function logCursor(name: string): () => string {
  const offset = readLog(name).length;
  return () => readLog(name).slice(offset);
}

export interface ApiResponse {
  status: number;
  body: unknown;
}

/**
 * Calls the legacy REST API from inside the GLPI container, so the default
 * "full access from localhost" API client applies without widening it.
 */
export function legacyApi(method: string, endpoint: string, headers: Record<string, string>, body?: unknown): ApiResponse {
  const args = ['exec', '-T', 'glpi', 'curl', '-s', '-o', '-', '-w', '\n%{http_code}', '-X', method];
  for (const [name, value] of Object.entries(headers)) {
    args.push('-H', `${name}: ${value}`);
  }
  if (body !== undefined) {
    args.push('-H', 'Content-Type: application/json', '--data-binary', '@-');
  }
  args.push(`http://127.0.0.1/apirest.php/${endpoint}`);
  const output = docker(args, body === undefined ? undefined : JSON.stringify(body)).trimEnd();
  const split = output.lastIndexOf('\n');
  const text = output.slice(0, split);
  let parsed: unknown = text;
  try {
    parsed = JSON.parse(text);
  } catch {
    // Non-JSON error bodies are returned as text.
  }
  return { status: Number(output.slice(split + 1)), body: parsed };
}

/**
 * Whether the core applies the user's own timezone to the DB session on every
 * request. GLPI 11.0.4 only does it on the login request (no `SetDbSessionVars`
 * listener), so dates typed by such users are stored in the server timezone.
 */
export function glpiAppliesUserTimezone(): boolean {
  const listener = '/var/www/glpi/src/Glpi/Kernel/Listener/PostBootListener/SetDbSessionVars.php';
  return docker(['exec', '-T', 'glpi', 'sh', '-c', `test -f ${listener} && echo yes || echo no`]).trim() === 'yes';
}

export function resetReservations(): void {
  sql('DELETE FROM glpi_reservations;');
}
