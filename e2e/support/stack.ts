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

export function setPluginConfig(key: string, value: string): void {
  glpiConsole('config:set', '--context=plugin:reservationcoffee', key, value);
}

export function pluginLog(): string {
  try {
    return docker(['exec', '-T', 'glpi', 'sh', '-c', 'cat /var/glpi/logs/reservationcoffee.log 2>/dev/null || true']);
  } catch {
    return '';
  }
}

export function resetReservations(): void {
  sql('DELETE FROM glpi_reservations;');
}
