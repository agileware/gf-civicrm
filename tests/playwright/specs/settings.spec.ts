/**
 * Suite H - Settings page and admin actions.
 *
 * Covers the CiviCRM settings page (Forms -> Settings -> CiviCRM) and its two actions: the
 * webhook URL merge tags replacement, and the rollback that restores the URLs it replaced.
 * Every case that mutates feeds or settings uses a temporary feed and restores state in a
 * `finally`, so spec ordering can never matter.
 */

import { test, expect, SETTINGS_PAGE, bodyText } from '../fixtures/base';
import { deleteOption, feed, formIds, withPluginSettings } from '../fixtures/gf';
import { wpEvalJson } from '../fixtures/civi';

const BACKUP_OPTION = 'gfcv_webhook_urls_backup';
const HARDCODED_URL =
  'http://localhost:8080/wp-json/civicrm/v3/rest?entity=FormProcessor&action=gfcvtest_contact&key=GFCVHARDSITE123&api_key=GFCVHARDAPI456&json=1';

/** A temporary Webhooks feed on the "GFCVTEST Webhook Fail" form; returns its id. */
function createTempFeed(url: string): number {
  return wpEvalJson<number>(
    `return (int) GFAPI::add_feed( ${formIds().webhookFail}, [
       'feedName' => 'GFCVTEST Temporary Feed',
       'requestURL' => ${JSON.stringify(url)},
       'requestMethod' => 'POST',
       'requestFormat' => 'json',
       'requestBodyType' => 'all_fields',
     ], 'gravityformswebhooks' );`
  );
}

function deleteFeed(feedId: number): void {
  wpEvalJson(`return GFAPI::delete_feed( ${feedId} );`);
}

function setBackup(backup: Record<number, string>): void {
  wpEvalJson(`return update_option( '${BACKUP_OPTION}', json_decode( ${JSON.stringify(JSON.stringify(backup))}, true ) );`);
}

/**
 * Run the merge tags replacement with PHP's error_log pointed at a temporary file, and return
 * what it logged. Where error_log() output normally lands depends on the server (under PHP-FPM
 * it can be pinned outside WordPress's control), so the log is captured directly instead.
 */
function logFromReplacement(): string {
  return wpEvalJson<string>(
    `$log = wp_tempnam( 'gfcvtest-log' );
     ini_set( 'error_log', $log );
     GFCiviCRM\\Upgrader::get_instance()->execute_webhook_url_merge_tags_replacements();
     $out = file_get_contents( $log );
     unlink( $log );
     return $out;`
  );
}

const rollbackUrl = (nonce: string) => `${SETTINGS_PAGE}&gf_webhook_urls_rollback_action=run&gf_webhook_urls_rollback_nonce=${nonce}`;

test.describe('Suite H - Settings page and admin actions', () => {
  test('H-01 the site key and API key inputs are password fields', async ({ adminPage: page }) => {
    await page.goto(SETTINGS_PAGE);

    await expect(page.locator('input[name="_gform_setting_gf_civicrm_site_key"]')).toHaveAttribute('type', 'password');
    await expect(page.locator('input[name="_gform_setting_gf_civicrm_api_key"]')).toHaveAttribute('type', 'password');
  });

  test('H-02 an import/export directory that is empty or contains ".." is rejected', async ({ adminPage: page }) => {
    await withPluginSettings({}, async () => {
      const directory = page.locator('input[name="_gform_setting_gf_civicrm_import_export_directory"]');
      for (const bad of ['../outside', 'a/../../b', '']) {
        await page.goto(SETTINGS_PAGE);
        await directory.fill(bad);
        await page.locator('#gform-settings-save').click();
        await page.waitForLoadState('load');

        await expect(page.locator('body')).toContainText('It cannot be empty or contain ".."');
        const stored = wpEvalJson<string>('return GFCiviCRM\\FieldsAddOn::get_instance()->get_plugin_setting( "gf_civicrm_import_export_directory" );');
        expect(stored).toBe('wp-content/uploads/gfcvtest-exports');
      }
    });
  });

  test('H-03 a path relative to the document root is accepted', async ({ adminPage: page }) => {
    await withPluginSettings({}, async () => {
      await page.goto(SETTINGS_PAGE);
      await page.locator('input[name="_gform_setting_gf_civicrm_import_export_directory"]').fill('/CRM/exports/');
      await page.locator('#gform-settings-save').click();
      await page.waitForLoadState('load');

      await expect(page.locator('body')).not.toContainText('It cannot be empty or contain ".."');
      const stored = wpEvalJson<string>('return GFCiviCRM\\FieldsAddOn::get_instance()->get_plugin_setting( "gf_civicrm_import_export_directory" );');
      expect(stored).toBe('/CRM/exports/');
    });
  });

  test('H-04 "Replace the Merge Tags" swaps hardcoded keys for merge tags and keeps a backup', async ({ adminPage: page }) => {
    const feedId = createTempFeed(HARDCODED_URL);
    try {
      await page.goto(SETTINGS_PAGE);
      await page.getByRole('link', { name: 'Replace the Merge Tags' }).click();
      await page.waitForLoadState('load');

      await expect(page.locator('body')).toContainText('Executed Webhook URL Merge Tags Replacements.');
      const url = feed(feedId).meta.requestURL as string;
      expect(url.startsWith('{rest_api_url}civicrm/v3/rest?')).toBe(true);
      expect(url).toContain('key={gf_civicrm_site_key}');
      expect(url).toContain('api_key={gf_civicrm_api_key}');
      expect(url).not.toContain('GFCVHARDAPI456');

      const backup = wpEvalJson<Record<string, string>>(`return get_option( '${BACKUP_OPTION}' );`);
      expect(backup[String(feedId)]).toBe(HARDCODED_URL);
    } finally {
      deleteFeed(feedId);
      deleteOption(BACKUP_OPTION);
    }
  });

  // The trigger (the settings page button) is covered by H-04; this case is about what the
  // replacement writes to the log, so it runs the replacement directly.
  test('H-05 the replacement logs feed IDs, not the URLs or keys', async () => {
    const feedId = createTempFeed(HARDCODED_URL);
    try {
      const log = logFromReplacement();
      expect(log).toContain(`Replaced the site key and API key with merge tags in the Gravity Forms Webhook URL for feed ID ${feedId}`);
      expect(log).not.toContain('GFCVHARDAPI456');
      expect(log).not.toContain('GFCVHARDSITE123');
    } finally {
      deleteFeed(feedId);
      deleteOption(BACKUP_OPTION);
    }
  });

  test('H-06 the revert button is offered only when a backup exists', async ({ adminPage: page }) => {
    const revert = page.getByRole('link', { name: 'Revert the Webhook URLs' });
    try {
      deleteOption(BACKUP_OPTION);
      await page.goto(SETTINGS_PAGE);
      await expect(revert).toHaveCount(0);

      setBackup({ 999999: 'http://example.test/old' });
      await page.goto(SETTINGS_PAGE);
      await expect(revert).toHaveCount(1);
    } finally {
      deleteOption(BACKUP_OPTION);
    }
  });

  test('H-07 "Revert the Webhook URLs" restores the URLs from the backup', async ({ adminPage: page }) => {
    const feedId = createTempFeed('{rest_api_url}civicrm/v3/rest?entity=FormProcessor&action=gfcvtest_contact&json=1');
    try {
      setBackup({ [feedId]: HARDCODED_URL });
      await page.goto(SETTINGS_PAGE);
      await page.getByRole('link', { name: 'Revert the Webhook URLs' }).click();
      await page.waitForLoadState('load');

      await expect(page.locator('body')).toContainText('Webhook URLs have been reverted to their original values.');
      expect(feed(feedId).meta.requestURL).toBe(HARDCODED_URL);
    } finally {
      deleteFeed(feedId);
      deleteOption(BACKUP_OPTION);
    }
  });

  test('H-08 the revert action with a wrong nonce is refused', async ({ adminPage: page }) => {
    const original = '{rest_api_url}civicrm/v3/rest?entity=FormProcessor&action=gfcvtest_contact&json=1';
    const feedId = createTempFeed(original);
    try {
      setBackup({ [feedId]: HARDCODED_URL });
      await page.goto(rollbackUrl('not-a-valid-nonce'));

      expect(await bodyText(page)).toContain('Security check failed');
      expect(feed(feedId).meta.requestURL).toBe(original);
    } finally {
      deleteFeed(feedId);
      deleteOption(BACKUP_OPTION);
    }
  });

  test('H-09 anonymous requests cannot trigger the revert, by the old or the new parameters', async ({ anonymousPage: page }) => {
    const original = '{rest_api_url}civicrm/v3/rest?entity=FormProcessor&action=gfcvtest_contact&json=1';
    const feedId = createTempFeed(original);
    try {
      setBackup({ [feedId]: HARDCODED_URL });
      await page.request.get('/wp-admin/admin-ajax.php?action=gfcvtest&page=gf_settings&rollback_webhook_urls=1');
      await page.request.get('/wp-admin/admin-ajax.php?action=gfcvtest&page=gf_settings&gf_webhook_urls_rollback_action=run&gf_webhook_urls_rollback_nonce=x');

      expect(feed(feedId).meta.requestURL).toBe(original);
    } finally {
      deleteFeed(feedId);
      deleteOption(BACKUP_OPTION);
    }
  });

  test('H-10 a form editor cannot trigger the revert', async ({ formEditorPage: page }) => {
    const original = '{rest_api_url}civicrm/v3/rest?entity=FormProcessor&action=gfcvtest_contact&json=1';
    const feedId = createTempFeed(original);
    try {
      setBackup({ [feedId]: HARDCODED_URL });
      await page.goto(rollbackUrl('x'));

      expect(feed(feedId).meta.requestURL).toBe(original);
    } finally {
      deleteFeed(feedId);
      deleteOption(BACKUP_OPTION);
    }
  });

  test('H-11 a form using the legacy checksum setting is named in an admin notice', async ({ adminPage: page }) => {
    const formId = formIds().counter;
    const setLegacy = (on: boolean) =>
      wpEvalJson(
        `$form = GFAPI::get_form( ${formId} );
         ${on ? "$form['gf-civicrm']['civicrm_auth_checksum'] = '1';" : "unset( $form['gf-civicrm']['civicrm_auth_checksum'] );"}
         return GFAPI::update_form( $form );`
      );
    setLegacy(true);
    try {
      await page.goto('/wp-admin/');

      await expect(page.locator('body')).toContainText('The Gravity Form "GFCVTEST Counter" has the nonfunctional CiviCRM auth checksum setting enabled');
    } finally {
      setLegacy(false);
    }
  });

  test('H-12 a missing API key is flagged in an admin notice', async ({ adminPage: page }) => {
    await withPluginSettings({ gf_civicrm_api_key: '' }, async () => {
      await page.goto(SETTINGS_PAGE);

      await expect(page.locator('body')).toContainText('API Key is missing in the Gravity Forms CiviCRM Settings.');
    });
  });
});
