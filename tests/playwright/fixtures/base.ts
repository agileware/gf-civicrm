import { test as base, expect, type Page, type Browser, type Locator } from '@playwright/test';
import users from './test-users.json';

type Fixtures = {
  /** Logged out: a visitor filling in a public form. Most negative tests run as this user. */
  anonymousPage: Page;
  /** A site member with a CiviCRM contact and saved payment tokens. */
  memberPage: Page;
  /** A second member, for cross-contact isolation. */
  otherMemberPage: Page;
  /** Can build forms and read entries, but must not reach the settings actions. */
  formEditorPage: Page;
  /** Can use the settings actions, but is not a CiviCRM administrator. */
  settingsManagerPage: Page;
  /** The WordPress administrator. CiviCRM grants administrators every permission. */
  adminPage: Page;
};

async function login(page: Page, username: string, password: string) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(username);
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await page.waitForURL(/wp-admin/);
}

async function loggedInPage(browser: Browser, username: string, password: string) {
  const context = await browser.newContext();
  const page = await context.newPage();
  await login(page, username, password);
  return { context, page };
}

function roleFixture(key: keyof typeof users) {
  return async ({ browser }: { browser: Browser }, use: (page: Page) => Promise<void>) => {
    const { context, page } = await loggedInPage(browser, users[key].username, users[key].password);
    await use(page);
    await context.close();
  };
}

export const test = base.extend<Fixtures>({
  anonymousPage: async ({ browser }, use) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await use(page);
    await context.close();
  },
  memberPage: roleFixture('member'),
  otherMemberPage: roleFixture('otherMember'),
  formEditorPage: roleFixture('formEditor'),
  settingsManagerPage: roleFixture('settingsManager'),
  adminPage: async ({ browser }, use) => {
    const { context, page } = await loggedInPage(
      browser,
      process.env.WP_ADMIN_USER || 'admin',
      process.env.WP_ADMIN_PASS || 'admin'
    );
    await use(page);
    await context.close();
  },
});

export { expect, users };

/**
 * Slugs of the pages created by seed-forms.php, one per form under test. Keeping them in one
 * place means a spec never hardcodes a slug that the seed script renames.
 */
export const PAGES = {
  sources: '/gfcv-test-sources/',
  defaults: '/gfcv-test-defaults/',
  contactSelect: '/gfcv-test-contact-select/',
  contactSelectRequired: '/gfcv-test-contact-select-required/',
  paymentToken: '/gfcv-test-payment-token/',
  paymentTokenRequired: '/gfcv-test-payment-token-required/',
  address: '/gfcv-test-address/',
  webhook: '/gfcv-test-webhook/',
  webhookFail: '/gfcv-test-webhook-fail/',
  mergeTags: '/gfcv-test-merge-tags/',
  counter: '/gfcv-test-counter/',
  nonce: '/gfcv-test-nonce/',
};

export const SETTINGS_PAGE = '/wp-admin/admin.php?page=gf_settings&subview=gf-civicrm';

/** The Gravity Forms confirmation text used by every seeded form without its own. */
export const SUBMITTED = 'GFCVTEST SUBMITTED';

/** A Gravity Forms field's input, by form and field id (and sub-input, e.g. "3.1"). */
export function gfInput(page: Page, formId: number, inputId: string | number): Locator {
  return page.locator(`#input_${formId}_${String(inputId).replace('.', '_')}`);
}

/** The option values of a select, in order. */
export async function optionValues(select: Locator): Promise<string[]> {
  return select.locator('option').evaluateAll((opts) => opts.map((o) => (o as HTMLOptionElement).value));
}

/** The option labels of a select, in order. */
export async function optionLabels(select: Locator): Promise<string[]> {
  return select.locator('option').evaluateAll((opts) => opts.map((o) => (o.textContent || '').trim()));
}

/**
 * Replace an option's value in the browser before submitting, as a modified POST would. The
 * option stays selected, so the form submits the new value.
 */
export async function setOptionValue(select: Locator, index: number, value: string): Promise<void> {
  await select.evaluate(
    (el, args) => {
      const s = el as HTMLSelectElement;
      s.options[args.index].value = args.value;
      s.selectedIndex = args.index;
    },
    { index, value }
  );
}

/** Submit a Gravity Forms form and wait for the resulting page. */
export async function submitForm(page: Page, formId: number): Promise<void> {
  // The forms are seeded with ajax="false", so submitting is a full-page POST navigation.
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.locator(`#gform_submit_button_${formId}`).click(),
  ]);
}

/** The page's visible text with whitespace collapsed. */
export async function bodyText(page: Page): Promise<string> {
  return ((await page.textContent('body')) || '').replace(/\s+/g, ' ').trim();
}
