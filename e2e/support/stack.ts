import { execFileSync } from 'node:child_process';
import * as path from 'node:path';
import { loadEnv } from './env';
import { seed } from './seed';

const env = loadEnv();
const composeArgs = ['compose', '--env-file', path.join(env.repoRoot, '.env'), '-f', path.join(env.repoRoot, 'docker', 'compose.e2e.yml')];

/** `user@host` of a remote homologation server (tools/remote-e2e.sh); unset for the Docker stack. */
const remoteSsh = process.env.E2E_REMOTE_SSH || undefined;
export const REMOTE_TOOLS_DIR = '/tmp/rc-e2e';

function docker(args: string[], input?: string): string {
  return execFileSync('docker', [...composeArgs, ...args], {
    cwd: env.repoRoot,
    input,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
}

function shellQuote(value: string): string {
  return `'${value.replaceAll("'", `'\\''`)}'`;
}

function remoteEnv(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing environment variable ${name} for the remote E2E mode`);
  }
  return value;
}

/** Runs tools/remote/glpi-remote.sh on the remote server. */
function remote(args: string[], input?: string): string {
  const variables = {
    RC_GLPI_DIR: remoteEnv('E2E_REMOTE_GLPI_DIR'),
    RC_GLPI_CONFIG_DIR: process.env.E2E_REMOTE_GLPI_CONFIG_DIR || '/etc/glpi',
    RC_GLPI_LOG_DIR: remoteEnv('E2E_REMOTE_GLPI_LOG_DIR'),
    RC_HOST: new URL(env.baseUrl).host,
    RC_API_ADDR: process.env.E2E_REMOTE_API_ADDR || '127.0.0.1',
  };
  const command = [
    ...Object.entries(variables).map(([name, value]) => `${name}=${shellQuote(value)}`),
    `${REMOTE_TOOLS_DIR}/glpi-remote.sh`,
    ...args.map(shellQuote),
  ].join(' ');
  return execFileSync(
    'ssh',
    // Unix socket paths are limited to ~104 bytes, so the macOS tmpdir is too long.
    ['-o', 'BatchMode=yes', '-o', 'ControlMaster=auto', '-o', 'ControlPath=/tmp/rc-ssh-%C', '-o', 'ControlPersist=120', remoteSsh as string, command],
    { input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] },
  );
}

/** Runs a SQL statement against the E2E database and returns rows as objects. */
export function sql<T extends Record<string, string | null> = Record<string, string | null>>(query: string): T[] {
  const output = remoteSsh
    ? remote(['sql'], query)
    : docker(
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
  return remoteSsh ? remote(['console', ...args]) : docker(['exec', '-T', '-u', 'www-data', 'glpi', 'bin/console', ...args]);
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

function logSize(name: string): number {
  const output = remoteSsh
    ? remote(['log-size', name])
    : docker(['exec', '-T', 'glpi', 'sh', '-c', `stat -c %s /var/glpi/logs/${name} 2>/dev/null || echo 0`]);
  return Number(output.trim());
}

function readLogFrom(name: string, offset: number): string {
  return remoteSsh
    ? remote(['log', name, String(offset)])
    : docker(['exec', '-T', 'glpi', 'sh', '-c', `tail -c +${offset + 1} /var/glpi/logs/${name} 2>/dev/null || true`]);
}

/**
 * Returns a function giving what was appended to a GLPI log file since this call.
 * Works on byte offsets so long-lived server logs are never transferred whole.
 */
export function logCursor(name: string): () => string {
  const offset = logSize(name);
  return () => readLogFrom(name, offset);
}

/** GLPI date format preference (0 = Y-m-d, 1 = d-m-Y, 2 = m-d-Y); the E2E users keep the global one. */
export function glpiDateFormat(): number {
  return Number(getConfig('core', 'date_format') ?? '0');
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
  if (remoteSsh) {
    const headerArgs = Object.entries(headers).map(([name, value]) => `${name}: ${value}`);
    return parseApiOutput(remote(['api', method, endpoint, ...headerArgs], body === undefined ? undefined : JSON.stringify(body)));
  }
  const args = ['exec', '-T', 'glpi', 'curl', '-s', '-o', '-', '-w', '\n%{http_code}', '-X', method];
  for (const [name, value] of Object.entries(headers)) {
    args.push('-H', `${name}: ${value}`);
  }
  if (body !== undefined) {
    args.push('-H', 'Content-Type: application/json', '--data-binary', '@-');
  }
  args.push(`http://127.0.0.1/apirest.php/${endpoint}`);
  return parseApiOutput(docker(args, body === undefined ? undefined : JSON.stringify(body)));
}

function parseApiOutput(raw: string): ApiResponse {
  const output = raw.trimEnd();
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
  const listener = 'src/Glpi/Kernel/Listener/PostBootListener/SetDbSessionVars.php';
  const answer = remoteSsh
    ? remote(['has-file', listener])
    : docker(['exec', '-T', 'glpi', 'sh', '-c', `test -f /var/www/glpi/${listener} && echo yes || echo no`]);
  return answer.trim() === 'yes';
}

function e2eLogins(): string {
  return Object.keys(seed().users)
    .map((key) => `'${env.credentials[key as keyof typeof env.credentials].login}'`)
    .join(',');
}

/**
 * Fails when the seed file was written for another GLPI: its user IDs would
 * then designate real people on this one.
 */
export function assertSeedMatchesTarget(): void {
  const rows = sql(`SELECT id, name FROM glpi_users WHERE name IN (${e2eLogins()})`);
  const ids = Object.fromEntries(rows.map((row) => [row.name, Number(row.id)]));
  for (const [key, id] of Object.entries(seed().users)) {
    const login = env.credentials[key as keyof typeof env.credentials].login;
    if (ids[login] !== id) {
      throw new Error(`The seed file does not match ${env.baseUrl} (${login}); run the seed against it first.`);
    }
  }
}

/** Removes reservations before each test; on a remote server only those made by the E2E users. */
export function resetReservations(): void {
  if (remoteSsh) {
    sql(`DELETE r FROM glpi_reservations r JOIN glpi_users u ON u.id = r.users_id WHERE u.name IN (${e2eLogins()});`);
    return;
  }
  sql('DELETE FROM glpi_reservations;');
}
