/**
 * Suite G - Merge tag scope.
 *
 * {gf_civicrm_site_key} and {gf_civicrm_api_key} must expand only inside Gravity Forms Webhooks
 * requests (URL, headers and body). Anywhere else - confirmations, notifications, field
 * defaults - they must stay as literal text, so the keys can never reach a visitor.
 *
 * The merge tags form's feed targets the capture endpoint, so G-05 can assert the real values
 * were sent while the copy stored on the entry keeps the placeholders.
 */

import { test, expect, PAGES, SUBMITTED, gfInput, submitForm, bodyText, users } from '../fixtures/base';
import { entryMeta, formIds, latestEntry } from '../fixtures/gf';
import { cvEval } from '../fixtures/civi';
import { capturedMail, capturedRequest, clearCapturedRequest, clearMail } from '../fixtures/capture';

const API_KEY = users.api.apiKey;
const API_TAG = '{gf_civicrm_api_key}';
const SITE_TAG = '{gf_civicrm_site_key}';
let siteKey = '';

async function submitMergeTags(page: import('@playwright/test').Page, echo: string) {
  const formId = formIds().mergeTags;
  await page.goto(PAGES.mergeTags);
  await gfInput(page, formId, 2).fill(echo);
  await submitForm(page, formId);
}

test.describe('Suite G - Merge tag scope', () => {
  test.beforeAll(() => {
    siteKey = cvEval('echo CIVICRM_SITE_KEY;');
  });

  test.beforeEach(() => {
    clearMail();
    clearCapturedRequest();
  });

  test('G-01 the key merge tags stay literal in a confirmation message', async ({ anonymousPage: page }) => {
    await submitMergeTags(page, 'hello');
    const text = await bodyText(page);

    expect(text).toContain(`API=[${API_TAG}]`);
    expect(text).toContain(`SITE=[${SITE_TAG}]`);
    expect(await page.content()).not.toContain(API_KEY);
    expect(await page.content()).not.toContain(siteKey);
  });

  test('G-02 a hidden field defaulting to the API key merge tag renders the literal tag', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.mergeTags);

    await expect(gfInput(page, formIds().mergeTags, 1)).toHaveValue(API_TAG);
    expect(await page.content()).not.toContain(API_KEY);
  });

  test('G-03 the key merge tags stay literal in a notification email', async ({ anonymousPage: page }) => {
    await submitMergeTags(page, 'notify');

    const notifications = capturedMail().filter((m) => String(m.to).includes('gfcv-notify@example.test'));
    expect(notifications).toHaveLength(1);
    expect(notifications[0].message).toContain(`NOTIFY API=[${API_TAG}]`);
    expect(notifications[0].message).not.toContain(API_KEY);
  });

  test('G-04 a webhook URL using the key merge tags authenticates with CiviCRM', async ({ anonymousPage: page }) => {
    const formId = formIds().webhook;
    await page.goto(PAGES.webhook);
    await gfInput(page, formId, 3).fill(`gfcvtest-g04-${Date.now()}@example.test`);
    await submitForm(page, formId);

    expect(await bodyText(page)).toContain(SUBMITTED);
    expect(entryMeta(latestEntry(formId)!.id, 'webhook_feed_response_status')).toBe('Success');
  });

  test('G-05 the key merge tags expand in the webhook URL, headers and body, but not in the stored copy', async ({ anonymousPage: page }) => {
    await submitMergeTags(page, 'g05');

    const sent = capturedRequest();
    expect(sent).not.toBeNull();
    expect(sent!.query.key).toBe(siteKey);
    expect(sent!.query.api_key).toBe(API_KEY);
    expect(sent!.headers.x_civi_key).toEqual([API_KEY]);
    expect(JSON.parse(sent!.body).site).toBe(siteKey);

    const stored = Object.values(entryMeta<Record<string, any>>(latestEntry(formIds().mergeTags)!.id, 'webhook_feed_request'))[0];
    expect(stored.request_url).toContain('api_key=REDACTED');
    expect(stored.request_args.headers['X-Civi-Key']).toBe('REDACTED');
    expect(JSON.parse(stored.request_args.body).site).toBe(SITE_TAG);
  });

  test('G-06 a submitter typing the API key merge tag gets it echoed literally', async ({ anonymousPage: page }) => {
    await submitMergeTags(page, API_TAG);

    expect(await bodyText(page)).toContain(`ECHO=[${API_TAG}]`);
    expect(await page.content()).not.toContain(API_KEY);
  });
});
