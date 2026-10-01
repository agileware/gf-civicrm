import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './specs',
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // A single worker everywhere, not just under CI. Several specs change site-wide plugin
  // settings (alert emails, country format, the import/export directory) and restore them in
  // a `finally`; a second worker submitting forms at the same time would see the changed
  // setting and pass or fail for the wrong reason.
  workers: 1,
  fullyParallel: false,
  timeout: process.env.CI ? 60000 : 45000,
  reporter: process.env.CI ? [['html', { open: 'never' }], ['list']] : 'list',
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      testIgnore: /remote-cmrf\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // Activating the CMRF connector changes plugin behaviour globally, so the remote
      // connection suite runs after everything else, never alongside it.
      name: 'remote-cmrf',
      testMatch: /remote-cmrf\.spec\.ts/,
      dependencies: ['chromium'],
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
