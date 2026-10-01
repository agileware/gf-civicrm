/**
 * Suite D - CiviCRM Payment Token.
 *
 * A Drop Down of a contact's saved payment methods for recurring contributions. The contact is
 * the one confirmed by a cid/cs checksum link, or else the logged-in contact - never a contact
 * named in the URL without a valid checksum. A submitted token must be one that was offered.
 */

import { test, expect, PAGES, SUBMITTED, gfInput, optionLabels, optionValues, setOptionValue, submitForm, bodyText } from '../fixtures/base';
import { entryCount, formIds, latestEntry } from '../fixtures/gf';
import { checksumFor, seededIds } from '../fixtures/ids';
import { wpCli } from '../fixtures/civi';

const NOT_AVAILABLE = 'Not Available';
// "Add new card" has a blank value, so Gravity Forms renders - and submits - its label as the
// value (GF_Field::get_choice_option_value). That is also what the entry stores.
const ADD_NEW_CARD = 'Add new card';
const INVALID = 'Invalid selection. Please select from the available choices.';

function tokenSelect(page: import('@playwright/test').Page) {
  return gfInput(page, formIds().paymentToken, 1);
}

test.describe('Suite D - CiviCRM Payment Token', () => {
  test('D-01 a valid cid/cs link lists that contact\'s active tokens with their details', async ({ anonymousPage: page }) => {
    const { memberContactId, memberListedTokens, memberCompletedToken } = seededIds();
    await page.goto(`${PAGES.paymentToken}?cid=${memberContactId}&cs=${checksumFor(memberContactId)}`);
    const select = tokenSelect(page);

    const values = await optionValues(select);
    expect(values).toEqual([ADD_NEW_CARD, ...memberListedTokens.map((t) => String(t.id))]);
    expect(values).not.toContain(String(memberCompletedToken.id));

    // Latest expiry first.
    const labels = await optionLabels(select);
    expect(labels[0]).toBe(ADD_NEW_CARD);
    expect(labels[1]).toBe('*2222 (Expires 06/31) - $20.00 every 1 year for Donation');
    expect(labels[2]).toBe('*1111 (Expires 12/30) - $10.00 every 1 month for Membership: GFCVTEST Membership');
  });

  test('D-02 an invalid checksum lists nothing for that contact', async ({ anonymousPage: page }) => {
    const { memberContactId, memberListedTokens } = seededIds();
    await page.goto(`${PAGES.paymentToken}?cid=${memberContactId}&cs=not-a-valid-checksum`);

    expect(await optionLabels(tokenSelect(page))).toEqual([NOT_AVAILABLE]);
    const html = await page.content();
    for (const token of memberListedTokens) {
      expect(html).not.toContain(`*${token.last4}`);
    }
  });

  test('D-03 a non-numeric cid renders the page without an error', async ({ anonymousPage: page }) => {
    const response = await page.goto(`${PAGES.paymentToken}?cid=abc&cs=x`);

    expect(response?.status()).toBe(200);
    expect(await optionLabels(tokenSelect(page))).toEqual([NOT_AVAILABLE]);
  });

  test('D-04 no cid/cs and no login lists nothing', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.paymentToken);

    expect(await optionLabels(tokenSelect(page))).toEqual([NOT_AVAILABLE]);
  });

  test('D-05 a logged-in member sees their own tokens', async ({ memberPage: page }) => {
    const { memberListedTokens } = seededIds();
    await page.goto(PAGES.paymentToken);

    expect(await optionValues(tokenSelect(page))).toEqual([ADD_NEW_CARD, ...memberListedTokens.map((t) => String(t.id))]);
  });

  test('D-06 a logged-in member with another contact\'s cid and an invalid cs still sees only their own tokens', async ({ memberPage: page }) => {
    const { otherContactId, otherMemberToken, memberListedTokens } = seededIds();
    await page.goto(`${PAGES.paymentToken}?cid=${otherContactId}&cs=not-a-valid-checksum`);

    const values = await optionValues(tokenSelect(page));
    expect(values).toEqual([ADD_NEW_CARD, ...memberListedTokens.map((t) => String(t.id))]);
    expect(values).not.toContain(String(otherMemberToken.id));
  });

  test('D-07 an offered token is accepted and stored', async ({ memberPage: page }) => {
    const { memberListedTokens } = seededIds();
    const formId = formIds().paymentToken;
    await page.goto(PAGES.paymentToken);

    await tokenSelect(page).selectOption(String(memberListedTokens[0].id));
    await submitForm(page, formId);

    expect(await bodyText(page)).toContain(SUBMITTED);
    expect(latestEntry(formId)?.['1']).toBe(String(memberListedTokens[0].id));
  });

  test('D-08 another contact\'s token is rejected', async ({ memberPage: page }) => {
    const { otherMemberToken } = seededIds();
    const formId = formIds().paymentToken;
    const before = entryCount(formId);
    await page.goto(PAGES.paymentToken);

    await setOptionValue(tokenSelect(page), 1, String(otherMemberToken.id));
    await submitForm(page, formId);

    await expect(page.locator(`#field_${formId}_1`)).toContainText(INVALID);
    expect(entryCount(formId)).toBe(before);
  });

  // Guards a regression found by this suite in 2.0.6: the offered-choices check compared the
  // submitted value with the raw choice values, so "Add new card" (submitted as its label) was
  // rejected as an invalid selection.
  test('D-09 "Add new card" is accepted on an optional field', async ({ memberPage: page }) => {
    const formId = formIds().paymentToken;
    await page.goto(PAGES.paymentToken);

    await tokenSelect(page).selectOption({ label: ADD_NEW_CARD });
    await submitForm(page, formId);

    expect(await bodyText(page)).toContain(SUBMITTED);
    expect(latestEntry(formId)?.['1']).toBe(ADD_NEW_CARD);
  });

  test('D-10 a required field with no token shows the required message', async ({ anonymousPage: page }) => {
    const formId = formIds().paymentTokenRequired;
    await page.goto(PAGES.paymentTokenRequired);

    await submitForm(page, formId);

    await expect(page.locator(`#field_${formId}_1`)).toContainText('This field is required.');
  });

  test('D-11 a valid cid/cs lists nothing if the checksum API is unavailable', async ({ anonymousPage: page }) => {
    const { memberContactId } = seededIds();
    const checksum = checksumFor(memberContactId);
    wpCli(['eval', 'civi_wp()->initialize(); civicrm_api3("Extension", "disable", ["keys" => "uk.co.mjwconsult.checksum"]);']);
    try {
      const response = await page.goto(`${PAGES.paymentToken}?cid=${memberContactId}&cs=${checksum}`);
      expect(response?.status()).toBe(200);
      expect(await optionLabels(tokenSelect(page))).toEqual([NOT_AVAILABLE]);
    } finally {
      wpCli(['eval', 'civi_wp()->initialize(); civicrm_api3("Extension", "enable", ["keys" => "uk.co.mjwconsult.checksum"]);']);
    }
  });
});
