/**
 * Suite E - Address field.
 *
 * The Address field's country list comes from CiviCRM (limited by the countryLimit setting,
 * keyed by ISO code) and its state list is filled in from CiviCRM by
 * js/gf-civicrm-address-fields.js. The seeded form's Webhooks feed sends the country to the
 * capture endpoint, so E-04/E-05 can assert what a webhook actually receives.
 */

import { test, expect, PAGES, SUBMITTED, gfInput, optionValues, submitForm, bodyText } from '../fixtures/base';
import { formIds, withPluginSettings } from '../fixtures/gf';
import { seededIds } from '../fixtures/ids';
import { wpCli } from '../fixtures/civi';
import { capturedRequest, clearCapturedRequest } from '../fixtures/capture';

async function fillAddress(page: import('@playwright/test').Page, country: string, state?: string) {
  const formId = formIds().address;
  await gfInput(page, formId, '1.1').fill('1 Test Street');
  await gfInput(page, formId, '1.3').fill('Testville');
  await gfInput(page, formId, '1.5').fill('3000');
  await gfInput(page, formId, '1.6').selectOption(country);
  if (state) {
    await gfInput(page, formId, '1.4').selectOption({ label: state });
  }
}

test.describe('Suite E - Address field', () => {
  test.beforeEach(() => {
    // The plugin caches CiviCRM's countries and states for 12 hours.
    wpCli(['transient', 'delete', 'gfcv_civicrm_countries']);
    wpCli(['transient', 'delete', 'gfcv_civicrm_stateprovinces']);
    clearCapturedRequest();
  });

  test('E-01 the country list is CiviCRM\'s limited list, keyed by ISO code, with the default selected', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.address);
    const country = gfInput(page, formIds().address, '1.6');

    const values = (await optionValues(country)).filter((v) => v !== '');
    expect(values.sort()).toEqual(['AU', 'NZ', 'US', seededIds().statelessCountryIso].sort());
    await expect(country).toHaveValue('AU');
  });

  test('E-02 choosing Australia fills the state list from CiviCRM', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.address);
    await gfInput(page, formIds().address, '1.6').selectOption('AU');

    const state = gfInput(page, formIds().address, '1.4');
    await expect(state.locator('option', { hasText: 'Victoria' })).toHaveCount(1);
  });

  test('E-03 a country with no states does not require a state', async ({ anonymousPage: page }) => {
    const formId = formIds().address;
    await page.goto(PAGES.address);

    await fillAddress(page, seededIds().statelessCountryIso);
    await submitForm(page, formId);

    expect(await bodyText(page)).toContain(SUBMITTED);
  });

  test('E-04 the webhook receives the country name with the "Country Name" setting', async ({ anonymousPage: page }) => {
    await withPluginSettings({ civicrm_address_country_format: 'name' }, async () => {
      await page.goto(PAGES.address);
      await fillAddress(page, 'AU', 'Victoria');
      await submitForm(page, formIds().address);

      expect(await bodyText(page)).toContain(SUBMITTED);
      expect(JSON.parse(capturedRequest()!.body).country).toBe('Australia');
    });
  });

  test('E-05 the webhook receives the ISO code with the "Country ISO Code" setting', async ({ anonymousPage: page }) => {
    await withPluginSettings({ civicrm_address_country_format: 'code' }, async () => {
      await page.goto(PAGES.address);
      await fillAddress(page, 'AU', 'Victoria');
      await submitForm(page, formIds().address);

      expect(await bodyText(page)).toContain(SUBMITTED);
      expect(JSON.parse(capturedRequest()!.body).country).toBe('AU');
    });
  });
});
