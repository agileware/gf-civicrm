/**
 * Suite F - Webhook submission to CiviCRM.
 *
 * End-to-end submissions through Gravity Forms Webhooks to the CiviCRM REST API and the
 * gfcvtest_contact Form Processor, plus the request/response data the plugin saves on the
 * entry and the alert email it sends when a webhook fails.
 *
 * Feeds run synchronously in this environment (see mu-plugins/gfcv-test-helpers.php), so the
 * entry meta is complete by the time the confirmation page loads.
 */

import { test, expect, PAGES, SUBMITTED, gfInput, submitForm, bodyText, users } from '../fixtures/base';
import { entryMeta, formIds, latestEntry, withPluginSettings, type Entry } from '../fixtures/gf';
import { civiApi4, cvEval } from '../fixtures/civi';
import { capturedMail, clearMail } from '../fixtures/capture';

const API_KEY = users.api.apiKey;
let siteKey = '';

type StoredRequest = Record<string, { request_url: string; request_args?: { body: string; headers: Record<string, string>; timeout: number } }>;

function uniqueEmail(tag: string): string {
  return `gfcvtest-${tag}-${Date.now()}@example.test`;
}

/** Fill in and submit the webhook form; returns the new entry and the email used. */
async function submitWebhook(page: import('@playwright/test').Page, tag: string): Promise<{ entry: Entry; email: string }> {
  const formId = formIds().webhook;
  const email = uniqueEmail(tag);
  await page.goto(PAGES.webhook);

  await gfInput(page, formId, 1).fill('Webhook');
  await gfInput(page, formId, 2).fill(`Test ${tag}`);
  await gfInput(page, formId, 3).fill(email);
  await gfInput(page, formId, 4).selectOption('gfcv_red');
  // Green is the option group's default, so it starts checked: set every box explicitly.
  await page.locator(`#input_${formId}_5 input[value="gfcv_red"]`).check();
  await page.locator(`#input_${formId}_5 input[value="gfcv_green"]`).uncheck();
  await page.locator(`#input_${formId}_5 input[value="gfcv_blue"]`).check();
  await gfInput(page, formId, 6).selectOption(['gfcv_green', 'gfcv_blue']);
  await gfInput(page, formId, 7).fill('1,234.50');
  // Gravity Forms sanitises upload names (spaces and accents are replaced), but keeps "@",
  // which urlencode() changes - so the encoding is observable.
  await gfInput(page, formId, 8).setInputFiles({ name: 'report@gfcv.txt', mimeType: 'text/plain', buffer: Buffer.from('gfcvtest') });
  await submitForm(page, formId);

  expect(await bodyText(page)).toContain(SUBMITTED);
  const entry = latestEntry(formId);
  expect(entry?.['3']).toBe(email);
  return { entry: entry!, email };
}

function storedRequest(entryId: string | number): { request_url: string; request_args: { body: string; headers: Record<string, string>; timeout: number } } {
  const stored = entryMeta<StoredRequest>(entryId, 'webhook_feed_request');
  const first = Object.values(stored)[0];
  return first as any;
}

test.describe('Suite F - Webhook submission to CiviCRM', () => {
  test.beforeAll(() => {
    siteKey = cvEval('echo CIVICRM_SITE_KEY;');
  });

  test.beforeEach(() => clearMail());

  test('F-01 a submission reaches the Form Processor and creates the contact', async ({ anonymousPage: page }) => {
    const { entry, email } = await submitWebhook(page, 'f01');

    expect(entryMeta(entry.id, 'webhook_feed_response_status')).toBe('Success');
    const emails = civiApi4<Array<{ contact_id: number }>>('Email.get', { select: ['contact_id'], where: [['email', '=', email]], checkPermissions: false });
    expect(emails).toHaveLength(1);
  });

  test('F-02 Checkboxes and Multi Select are sent as JSON arrays', async ({ anonymousPage: page }) => {
    const { entry } = await submitWebhook(page, 'f02');
    const body = JSON.parse(storedRequest(entry.id).request_args.body);

    expect(body.colours).toEqual(['gfcv_red', 'gfcv_blue']);
    expect(body.colours_multi).toEqual(['gfcv_green', 'gfcv_blue']);
  });

  test('F-03 a user-defined price is sent as a plain number', async ({ anonymousPage: page }) => {
    const { entry } = await submitWebhook(page, 'f03');

    expect(JSON.parse(storedRequest(entry.id).request_args.body).amount).toBe('1234.5');
  });

  test('F-04 an uploaded file\'s URL path is URL-encoded', async ({ anonymousPage: page }) => {
    const { entry } = await submitWebhook(page, 'f04');
    const attachment: string = JSON.parse(storedRequest(entry.id).request_args.body).attachment;

    expect(entry['8']).toContain('@');
    expect(attachment).toMatch(/^https?:\/\//);
    expect(attachment).toContain('report%40gfcv');
    expect(attachment).not.toContain('@');
  });

  test('F-05 the entry shows the webhook meta boxes and the status column', async ({ anonymousPage, adminPage }) => {
    const { entry } = await submitWebhook(anonymousPage, 'f05');
    const formId = formIds().webhook;

    await adminPage.goto(`/wp-admin/admin.php?page=gf_entries&view=entry&id=${formId}&lid=${entry.id}`);
    await expect(adminPage.locator('body')).toContainText('Webhook Request');
    await expect(adminPage.locator('body')).toContainText('Webhook Response');

    await adminPage.goto(`/wp-admin/admin.php?page=gf_entries&id=${formId}`);
    await expect(adminPage.locator('body')).toContainText('Webhook Response Status');
  });

  test('F-06 the stored request carries no keys, and the timeout is 120 seconds', async ({ anonymousPage: page }) => {
    const { entry } = await submitWebhook(page, 'f06');
    const stored = storedRequest(entry.id);

    expect(stored.request_url).toContain('key=REDACTED');
    expect(stored.request_url).toContain('api_key=REDACTED');
    expect(JSON.stringify(stored)).not.toContain(API_KEY);
    expect(JSON.stringify(stored)).not.toContain(siteKey);
    expect(stored.request_args.timeout).toBe(120);

    // Authentication headers: the merge tags form's feed sends X-Civi-Key and Authorization.
    const formId = formIds().mergeTags;
    await page.goto(PAGES.mergeTags);
    await gfInput(page, formId, 2).fill('f06');
    await submitForm(page, formId);
    const headers = storedRequest(latestEntry(formId)!.id).request_args.headers;
    expect(headers['X-Civi-Key']).toBe('REDACTED');
    expect(headers['Authorization']).toBe('REDACTED');
  });

  test('F-07 a failed webhook is recorded and sends one alert without keys', async ({ anonymousPage: page }) => {
    const formId = formIds().webhookFail;
    await page.goto(PAGES.webhookFail);
    await gfInput(page, formId, 1).fill(uniqueEmail('f07'));
    await submitForm(page, formId);

    expect(entryMeta(latestEntry(formId)!.id, 'webhook_feed_response_status')).toBe('Fail');
    const alerts = capturedMail().filter((m) => String(m.to).includes('gfcv-alerts@example.test'));
    expect(alerts).toHaveLength(1);
    expect(alerts[0].message).toContain('key=REDACTED');
    expect(alerts[0].message).toContain('api_key=REDACTED');
    expect(alerts[0].message).not.toContain(API_KEY);
    expect(alerts[0].message).not.toContain(siteKey);
  });

  test('F-08 no alert is sent when alerts are disabled', async ({ anonymousPage: page }) => {
    await withPluginSettings({ enable_emails: '0' }, async () => {
      const formId = formIds().webhookFail;
      await page.goto(PAGES.webhookFail);
      await gfInput(page, formId, 1).fill(uniqueEmail('f08'));
      await submitForm(page, formId);

      expect(entryMeta(latestEntry(formId)!.id, 'webhook_feed_response_status')).toBe('Fail');
      expect(capturedMail().filter((m) => String(m.to).includes('gfcv-alerts@example.test'))).toHaveLength(0);
    });
  });

  test('F-09 a form editor viewing an entry sees the meta boxes but no key values', async ({ anonymousPage, formEditorPage }) => {
    const { entry } = await submitWebhook(anonymousPage, 'f09');

    await formEditorPage.goto(`/wp-admin/admin.php?page=gf_entries&view=entry&id=${formIds().webhook}&lid=${entry.id}`);
    await expect(formEditorPage.locator('body')).toContainText('Webhook Request');
    const html = await formEditorPage.content();
    expect(html).not.toContain(API_KEY);
    expect(html).not.toContain(siteKey);
  });
});
