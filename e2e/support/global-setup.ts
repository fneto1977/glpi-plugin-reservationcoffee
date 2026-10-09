import * as fs from 'node:fs';
import { seedPath } from './paths';
import { loadEnv } from './env';

export default async function globalSetup(): Promise<void> {
  const env = loadEnv();
  if (!fs.existsSync(seedPath)) {
    throw new Error(`Missing ${seedPath}; run tools/seed-e2e.sh first.`);
  }
  const response = await fetch(env.baseUrl, { redirect: 'manual' }).catch(() => null);
  if (response === null || response.status >= 500) {
    throw new Error(`GLPI is not reachable at ${env.baseUrl}; start docker/compose.e2e.yml first.`);
  }
}
