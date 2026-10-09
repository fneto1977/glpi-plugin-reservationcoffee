import { defineConfig, devices } from '@playwright/test';
import { loadEnv } from './support/env';

const env = loadEnv();

export default defineConfig({
  testDir: './tests',
  // The GLPI session and the plugin pending state are per user; tests that
  // share a user must not interleave.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  globalSetup: './support/global-setup.ts',
  use: {
    baseURL: env.baseUrl,
    locale: 'pt-BR',
    timezoneId: env.timezone,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        ...(env.browserChannel ? { channel: env.browserChannel } : {}),
      },
    },
  ],
});
