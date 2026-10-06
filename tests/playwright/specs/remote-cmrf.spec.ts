/**
 * Suite K - Remote connection via CMRF (and I-01, the settings page connection checks).
 *
 * Activates the CiviCRM McRestFace connector with a profile whose curl connector points back at
 * the same site on http://localhost/ (port 80 inside the container), so every CiviCRM call goes
 * over REST as the API user. Activating CMRF changes plugin behaviour globally, so this suite
 * runs as its own Playwright project, after every other suite (see playwright.config.ts).
 *
 * K-04 (Payment Token lookup through the SearchKit display) depends on site-specific CiviCRM
 * configuration and is verified manually (test plan section 7.1).
 */

import { test, expect, PAGES, SETTINGS_PAGE, SUBMITTED, gfInput, optionLabels, submitForm, bodyText, users } from '../fixtures/base';
import { entryMeta, feedsForForm, formIds, latestEntry, setFeedUrl, updatePluginSettings, withPluginSettings } from '../fixtures/gf';
import { civiApi4, cvEval, wpCli, wpEvalJson } from '../fixtures/civi';

test.describe.configure({ mode: 'serial' });

const REMOTE_URL = 'http://localhost/wp-json/civicrm/v3/rest';
let profileName = '';

function clearCmrfCalls(): void {
  wpEvalJson(`global $wpdb; return $wpdb->query( "DELETE FROM {$wpdb->prefix}wpcmrf_core_call" );`);
}

function cmrfCallCount(entity: string): number {
  return wpEvalJson<number>(
    `global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcmrf_core_call WHERE request LIKE %s", '%' . $wpdb->esc_like( ${JSON.stringify(entity)} ) . '%' ) );`
  );
}

test.describe('Suite K - Remote connection via CMRF', () => {
  test.beforeAll(() => {
    wpCli(['plugin', 'activate', 'connector-civicrm-mcrestface']);
    const siteKey = cvEval('echo CIVICRM_SITE_KEY;');
    const profileId = wpEvalJson<number>(
      `global $wpdb;
       $table = $wpdb->prefix . 'wpcivimrf_profile';
       $wpdb->delete( $table, [ 'label' => 'GFCVTEST Remote' ] );
       $wpdb->insert( $table, [
         'label'     => 'GFCVTEST Remote',
         'connector' => 'curl',
         'url'       => ${JSON.stringify(REMOTE_URL)},
         'urlV4'     => '',
         'site_key'  => ${JSON.stringify(siteKey)},
         'api_key'   => ${JSON.stringify(users.api.apiKey)},
       ] );
       return (int) $wpdb->insert_id;`
    );
    profileName = `wpcmrf_profile_${profileId}`;
    updatePluginSettings({ civicrm_rest_connection: profileName });
  });

  test.afterAll(() => {
    updatePluginSettings({ civicrm_rest_connection: '' });
    wpEvalJson(`global $wpdb; return $wpdb->delete( $wpdb->prefix . 'wpcivimrf_profile', [ 'label' => 'GFCVTEST Remote' ] );`);
    clearCmrfCalls();
    wpCli(['plugin', 'deactivate', 'connector-civicrm-mcrestface']);
    wpCli(['transient', 'delete', '--all']);
  });

  test.beforeEach(() => clearCmrfCalls());

  test('K-01 CiviCRM Source choices are fetched over the CMRF connection', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.sources);

    expect((await optionLabels(gfInput(page, formIds().sources, 1))).filter((l) => l !== '- None -')).toEqual(['Red', 'Green', 'Blue']);
    expect(cmrfCallCount('OptionGroup')).toBeGreaterThan(0);
  });

  test('K-02 a webhook using {gf_civicrm_rest_url} and the key merge tags uses the profile\'s URL and keys', async ({ anonymousPage: page }) => {
    const formId = formIds().webhook;
    const feed = feedsForForm(formId)[0];
    const originalUrl = feed.meta.requestURL;
    const email = `gfcvtest-k02-${Date.now()}@example.test`;

    // A wrong API key in the plugin settings proves the profile's key is the one used.
    await withPluginSettings({ gf_civicrm_api_key: 'gfcvtest-wrong-key' }, async () => {
      setFeedUrl(feed.id, '{gf_civicrm_rest_url}?entity=FormProcessor&action=gfcvtest_contact&key={gf_civicrm_site_key}&api_key={gf_civicrm_api_key}&json=1');
      try {
        await page.goto(PAGES.webhook);
        await gfInput(page, formId, 3).fill(email);
        await submitForm(page, formId);

        expect(await bodyText(page)).toContain(SUBMITTED);
        expect(entryMeta(latestEntry(formId)!.id, 'webhook_feed_response_status')).toBe('Success');
        expect(civiApi4<unknown[]>('Email.get', { select: ['id'], where: [['email', '=', email]], checkPermissions: false })).toHaveLength(1);
      } finally {
        setFeedUrl(feed.id, originalUrl);
      }
    });
  });

  test('K-03 the Payment Token field lists nothing without a cid/cs, and sends no token lookup', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.paymentToken);

    expect(await optionLabels(gfInput(page, formIds().paymentToken, 1))).toEqual(['Not Available']);
    expect(cmrfCallCount('SearchDisplay')).toBe(0);
  });

  // A saved search is run over APIv4, and this profile has no APIv4 URL.
  test('K-07 without an APIv4 URL a saved search source lists no contacts, and a group source still works', async ({ anonymousPage: page }) => {
    const formId = formIds().contactSelect;
    await page.goto(PAGES.contactSelect);

    expect(await optionLabels(gfInput(page, formId, 2))).toEqual(['No Contacts in this Group']);
    expect(await optionLabels(gfInput(page, formId, 1))).toEqual(['Groupone, Gfcvtest', 'Grouptwo, Gfcvtest']);
  });

  test('K-05 the import page warns that files must be reachable from the remote installation', async ({ adminPage: page }) => {
    await page.goto('/wp-admin/admin.php?page=gf_export&subview=import_gfcivicrm');

    await expect(page.locator('body')).toContainText('The import file must be accessible to the remote installation');
  });

  test('K-06 selecting a CMRF profile hides the site key and API key settings', async ({ adminPage: page }) => {
    await page.goto(SETTINGS_PAGE);
    await page.locator('#civicrm_rest_connection').selectOption(profileName);

    // Selecting a profile also fires eleven connection checks from the same session, and PHP
    // session locking serialises them with the profile-type request that hides the section.
    await expect(page.locator('#gform-settings-section-gfcv-api-settings')).toBeHidden({ timeout: 30000 });
  });

  test('I-01 selecting a connection profile runs the pre-flight checks, and each shows OK', async ({ adminPage: page }) => {
    await page.goto(SETTINGS_PAGE);
    await page.locator('#civicrm_rest_connection').selectOption(profileName);

    const items = page.locator('#api-checks-results li.api-check-item');
    await expect(items.first()).toBeVisible();
    await expect(page.locator('#api-checks-results li.pending')).toHaveCount(0, { timeout: 30000 });

    const failed = await page.locator('#api-checks-results li.failed').allTextContents();
    expect(failed.map((t) => t.replace(/\s+/g, ' ').trim())).toEqual([]);
  });
});
