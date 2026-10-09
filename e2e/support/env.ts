import { config as loadDotenv } from 'dotenv';
import * as path from 'node:path';

export type E2EUserKey = 'selfservice' | 'selfservice2' | 'tech' | 'otherentity' | 'admin';

export interface E2EEnv {
  repoRoot: string;
  baseUrl: string;
  timezone: string;
  browserChannel?: string;
  credentials: Record<E2EUserKey, { login: string; password: string }>;
}

function required(name: string): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Missing environment variable ${name}; see .env.example`);
  }
  return value;
}

export function loadEnv(): E2EEnv {
  const repoRoot = path.resolve(__dirname, '..', '..');
  loadDotenv({ path: path.join(repoRoot, '.env'), quiet: true });

  return {
    repoRoot,
    baseUrl: process.env.E2E_BASE_URL ?? `http://127.0.0.1:${process.env.E2E_HTTP_PORT ?? '8080'}`,
    timezone: process.env.E2E_TIMEZONE ?? 'America/Sao_Paulo',
    browserChannel: process.env.E2E_BROWSER_CHANNEL || undefined,
    credentials: {
      selfservice: { login: 'e2e.selfservice', password: required('E2E_PASSWORD_SELFSERVICE') },
      selfservice2: { login: 'e2e.selfservice2', password: required('E2E_PASSWORD_SELFSERVICE2') },
      tech: { login: 'e2e.tech', password: required('E2E_PASSWORD_TECH') },
      otherentity: { login: 'e2e.otherentity', password: required('E2E_PASSWORD_OTHERENTITY') },
      admin: { login: 'glpi', password: required('E2E_GLPI_ADMIN_PASSWORD') },
    },
  };
}
