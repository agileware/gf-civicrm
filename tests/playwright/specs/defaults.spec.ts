/**
 * Suite B - Form Processor defaults and merge tags.
 *
 * Default values fetched from CiviCRM Form Processors through {civicrm_fp.<processor>.<field>}
 * merge tags, with retrieval criteria (cid, cs) taken from the URL.
 */

import { test, expect, PAGES, gfInput } from '../fixtures/base';
import { formIds } from '../fixtures/gf';
import { checksumFor, seededIds } from '../fixtures/ids';

// Field ids in the seeded "GFCVTEST Defaults" form.
const F = { firstName: 1, lastNameViaDefaultFp: 2, html: 3, restricted: 4, missingProcessor: 5 };

test.describe('Suite B - Form Processor defaults and merge tags', () => {
  test('B-01 a {civicrm_fp.…} default is filled from a valid cid/cs link', async ({ anonymousPage: page }) => {
    const { memberContactId } = seededIds();
    await page.goto(`${PAGES.defaults}?cid=${memberContactId}&cs=${checksumFor(memberContactId)}`);

    await expect(gfInput(page, formIds().defaults, F.firstName)).toHaveValue('Gfcvtest');
  });

  test('B-02 an invalid checksum leaves the default empty', async ({ anonymousPage: page }) => {
    const { memberContactId } = seededIds();
    await page.goto(`${PAGES.defaults}?cid=${memberContactId}&cs=not-a-valid-checksum`);

    await expect(gfInput(page, formIds().defaults, F.firstName)).toHaveValue('');
    await expect(page.locator(`#gform_${formIds().defaults}`)).toBeVisible();
  });

  test('B-03 {civicrm_fp.default_fp.…} resolves through the form\'s default Form Processor, in fields and HTML', async ({ anonymousPage: page }) => {
    const { memberContactId } = seededIds();
    await page.goto(`${PAGES.defaults}?cid=${memberContactId}&cs=${checksumFor(memberContactId)}`);

    await expect(gfInput(page, formIds().defaults, F.lastNameViaDefaultFp)).toHaveValue('Member');
    await expect(page.locator(`#field_${formIds().defaults}_${F.html}`)).toContainText('HTMLFIRST=[Gfcvtest]');
  });

  test('B-04 {gf_civicrm_rest_url} renders the site REST URL on a local connection', async ({ anonymousPage: page }, testInfo) => {
    await page.goto(PAGES.defaults);
    const base = String(testInfo.project.use.baseURL).replace(/\/$/, '');

    await expect(page.locator(`#field_${formIds().defaults}_${F.html}`)).toContainText(`RESTURL=[${base}/wp-json/]`);
  });

  test('B-05 an anonymous visitor does not get defaults from a permission-restricted Form Processor', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.defaults);

    await expect(gfInput(page, formIds().defaults, F.restricted)).toHaveValue('');
  });

  test('B-06 an administrator gets the restricted Form Processor\'s default', async ({ adminPage: page }) => {
    await page.goto(PAGES.defaults);

    await expect(gfInput(page, formIds().defaults, F.restricted)).toHaveValue('GFCVTEST_RESTRICTED');
  });

  test('B-07 a merge tag naming a missing Form Processor renders empty, and the form still renders', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.defaults);

    await expect(gfInput(page, formIds().defaults, F.missingProcessor)).toHaveValue('');
    await expect(page.locator(`#gform_submit_button_${formIds().defaults}`)).toBeVisible();
  });
});
