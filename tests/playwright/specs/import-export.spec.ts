/**
 * Suite J - GF CiviCRM import and export.
 *
 * Export writes a form, its feeds and its Form Processors to the import/export directory;
 * import reads them back. The cases run in order: J-01 produces the files the later cases use.
 *
 * Form Processor imports are POSTed directly: the import page lists Form Processor files from
 * the wrong directory, so none are offered for selection in a default setup (test plan 7.2).
 */

import { test, expect, bodyText } from '../fixtures/base';
import { feedsForForm, formIds, withPluginSettings } from '../fixtures/gf';
import { civiApi3, wpEvalJson } from '../fixtures/civi';
import type { Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const EXPORT_PAGE = '/wp-admin/admin.php?page=gf_export&subview=export_gfcivicrm';
const IMPORT_PAGE = '/wp-admin/admin.php?page=gf_export&subview=import_gfcivicrm';
const BASE = 'wp-content/uploads/gfcvtest-exports';
const SLUG = 'gfcvtest_webhook';

function exportFiles(): Record<string, boolean | string> {
  return wpEvalJson(
    `$b = ABSPATH . '${BASE}';
     return [
       'form' => file_exists( "$b/${SLUG}/form--${SLUG}.json" ),
       'feeds' => file_exists( "$b/${SLUG}/feeds--${SLUG}.json" ),
       'processor' => file_exists( "$b/form-processors/gfcvtest_contact.json" ),
       'formHtaccess' => (string) @file_get_contents( "$b/${SLUG}/.htaccess" ),
       'processorHtaccess' => (string) @file_get_contents( "$b/form-processors/.htaccess" ),
       'formIndex' => file_exists( "$b/${SLUG}/index.php" ),
       'processorIndex' => file_exists( "$b/form-processors/index.php" ),
       'baseHtaccess' => (string) @file_get_contents( "$b/.htaccess" ),
       'baseIndex' => file_exists( "$b/index.php" ),
       'failDir' => is_dir( "$b/gfcvtest_webhook_fail" ),
     ];`
  );
}

function removeExports(): void {
  wpEvalJson(
    `$b = ABSPATH . '${BASE}';
     if ( is_dir( $b ) ) {
       $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $b, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
       foreach ( $it as $f ) { $f->isDir() ? rmdir( $f ) : unlink( $f ); }
       rmdir( $b );
     }
     return true;`
  );
}

async function exportForm(page: Page, formId: number): Promise<void> {
  await page.goto(EXPORT_PAGE);
  await page.locator(`#gf_form_id_${formId}`).check();
  // The page carries more than one "Export Selected" button; the plugin's posts to its own action.
  await Promise.all([page.waitForNavigation(), page.locator('button[formaction*="action=gf_civicrm_export"]').click()]);
}

/** POST a Form Processor import as the given user, using the nonce from their import page. */
async function postProcessorImport(page: Page, processor: string): Promise<void> {
  await page.goto(IMPORT_PAGE);
  const nonce = await page.locator('form input[name="_wpnonce"]').first().inputValue();
  await page.request.post(IMPORT_PAGE, {
    form: { _wpnonce: nonce, _wp_http_referer: IMPORT_PAGE, 'import_form_processor[]': processor },
  });
  // The import redirects after output has started, so its status notice appears on the next load.
  await page.goto(IMPORT_PAGE);
}

function processorId(name: string): number {
  const result = civiApi3<{ values: Record<string, { id: string }> }>('FormProcessorInstance', 'get', { name, sequential: 0 });
  return Number(Object.values(result.values)[0]?.id);
}

test.describe('Suite J - GF CiviCRM import and export', () => {
  // Start with no export directory at all, so J-01 also proves the export creates it.
  test.beforeAll(() => removeExports());

  test('J-01 exporting a form creates the directory and writes the form, its feeds and its Form Processor', async ({ adminPage: page }) => {
    await exportForm(page, formIds().webhook);

    const files = exportFiles();
    expect(files.form).toBe(true);
    expect(files.feeds).toBe(true);
    expect(files.processor).toBe(true);
  });

  test('J-02 the base directory and each export directory are protected in place', async () => {
    const files = exportFiles();
    for (const htaccess of [files.baseHtaccess, files.formHtaccess, files.processorHtaccess] as string[]) {
      expect(htaccess).toContain('Require all denied');
      expect(htaccess).toContain('Deny from all');
    }
    expect(files.baseIndex).toBe(true);
    expect(files.formIndex).toBe(true);
    expect(files.processorIndex).toBe(true);
  });

  test('J-03 an exported file cannot be downloaded over HTTP', async ({ anonymousPage: page }) => {
    const response = await page.request.get(`/${BASE}/${SLUG}/form--${SLUG}.json`);

    expect(response.status()).toBe(403);
  });

  test('J-04 importing the export as a new form recreates the form and its active feeds', async ({ adminPage: page }) => {
    const before = wpEvalJson<number[]>(`return array_map( fn( $f ) => (int) $f['id'], GFAPI::get_forms( null ) );`);
    let created: number[] = [];
    try {
      await page.goto(IMPORT_PAGE);
      await page.locator(`input[name="import_form[${SLUG}]"]`).check();
      await page.locator(`select[name="import_form_into[${SLUG}]"]`).selectOption('create');
      await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Import Selected' }).click()]);

      const after = wpEvalJson<Array<{ id: string; title: string }>>(`return array_map( fn( $f ) => [ 'id' => $f['id'], 'title' => $f['title'] ], GFAPI::get_forms( null ) );`);
      created = after.map((f) => Number(f.id)).filter((id) => !before.includes(id));
      expect(created).toHaveLength(1);
      // Gravity Forms keeps titles unique, so the copy is titled "GFCVTEST Webhook (1)".
      expect(after.find((f) => Number(f.id) === created[0])?.title).toMatch(/^GFCVTEST Webhook/);

      const feeds = feedsForForm(created[0]);
      expect(feeds.length).toBeGreaterThan(0);
      expect(feeds.every((f) => String(f.is_active) === '1')).toBe(true);
    } finally {
      for (const id of created) {
        wpEvalJson(`return GFAPI::delete_form( ${id} );`);
      }
    }
  });

  test('J-05 a form editor gets no GF CiviCRM import/export, and cannot export by POST', async ({ formEditorPage: page }) => {
    await page.goto('/wp-admin/admin.php?page=gf_export');
    await expect(page.locator('body')).not.toContainText('Export GF CiviCRM');

    // The Gravity Forms "Export Forms" page issues the same nonce the plugin's export checks.
    await page.goto('/wp-admin/admin.php?page=gf_export&subview=export_form');
    const nonce = await page.locator('input[name="gf_export_forms_nonce"]').inputValue();
    const response = await page.request.post('/wp-admin/admin-post.php?action=gf_civicrm_export', {
      form: { gf_export_forms_nonce: nonce, _wp_http_referer: '/wp-admin/admin.php?page=gf_export', 'gf_form_id[]': String(formIds().webhookFail) },
    });

    expect(response.status()).toBe(403);
    expect(exportFiles().failDir).toBe(false);
  });

  test('J-06 a settings manager without "administer CiviCRM" cannot import a Form Processor locally', async ({ settingsManagerPage: page }) => {
    const before = processorId('gfcvtest_contact');
    await postProcessorImport(page, 'gfcvtest_contact');

    await expect(page.locator('body')).toContainText('requires the "administer CiviCRM" permission');
    expect(processorId('gfcvtest_contact')).toBe(before);
  });

  test('J-07 an administrator can import the Form Processor', async ({ adminPage: page }) => {
    await postProcessorImport(page, 'gfcvtest_contact');

    await expect(page.locator('body')).toContainText('was replaced by source: gfcvtest_contact.json');
  });

  test('J-08 an export with an invalid directory setting is refused and writes nothing', async ({ adminPage: page }) => {
    await withPluginSettings({ gf_civicrm_import_export_directory: '../gfcvtest-outside' }, async () => {
      await exportForm(page, formIds().webhookFail);

      expect(await bodyText(page)).toContain('The GF CiviCRM Import/Export Directory setting is invalid.');
      expect(wpEvalJson<boolean>(`return is_dir( dirname( ABSPATH ) . '/gfcvtest-outside' );`)).toBe(false);
    });
  });
});
