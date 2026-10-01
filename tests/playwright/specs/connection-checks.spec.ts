/**
 * Suite I - Connection checks.
 *
 * The AJAX handlers behind the settings page's pre-flight checks. The nonce they require is
 * only printed on the settings page, so a lower-privileged user would never normally hold
 * one; the test-only [gfcvtest_nonce] shortcode issues one for the logged-in user, so the
 * capability check can be tested on its own. I-01 (the settings page UI) needs a CMRF
 * connection profile and lives in remote-cmrf.spec.ts.
 */

import { test, expect, PAGES } from '../fixtures/base';
import type { Page } from '@playwright/test';

async function nonceFor(page: Page): Promise<string> {
  await page.goto(PAGES.nonce);
  return ((await page.locator('#gfcvtest-nonce').textContent()) || '').trim();
}

function ajax(page: Page, form: Record<string, string>) {
  return page.request.post('/wp-admin/admin-ajax.php', { form });
}

test.describe('Suite I - Connection checks', () => {
  test('I-02 an administrator gets a status only, with no CiviCRM data', async ({ adminPage: page }) => {
    const response = await ajax(page, {
      action: 'check_civi_connection',
      security: await nonceFor(page),
      profile: '_local_civi_',
      check_type: 'settings',
    });

    expect(response.status()).toBe(200);
    expect(await response.json()).toEqual({ success: true, data: { message: 'OK' } });
  });

  test('I-03 a form editor with their own nonce is refused the connection check', async ({ formEditorPage: page }) => {
    const response = await ajax(page, {
      action: 'check_civi_connection',
      security: await nonceFor(page),
      profile: '_local_civi_',
      check_type: 'settings',
    });

    expect(response.status()).toBe(403);
    expect((await response.json()).success).toBe(false);
  });

  test('I-04 a form editor with their own nonce is refused the profile type check', async ({ formEditorPage: page }) => {
    const response = await ajax(page, {
      action: 'check_connection_profile_type',
      security: await nonceFor(page),
      profile: '_local_civi_',
    });

    expect(response.status()).toBe(403);
    expect((await response.json()).success).toBe(false);
  });

  test('I-05 anonymous requests are rejected by both actions', async ({ anonymousPage: page }) => {
    for (const action of ['check_civi_connection', 'check_connection_profile_type']) {
      const response = await ajax(page, { action, security: 'x', profile: '_local_civi_', check_type: 'settings' });

      // No wp_ajax_nopriv_ handler is registered, so admin-ajax.php answers "0" with a 400.
      expect(response.status()).toBe(400);
      expect((await response.text()).trim()).toBe('0');
    }
  });
});
