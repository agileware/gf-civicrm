/**
 * Suite A - CiviCRM Source options.
 *
 * Drop Down, Radio Buttons, Checkboxes and Multi Select fields whose choices come from a
 * CiviCRM option group or a Form Processor input. The seeded option group gfcvtest_colours
 * has values that differ from their labels (gfcv_red / Red), so a test can always tell which
 * one reached the entry.
 */

import { test, expect, PAGES, SUBMITTED, gfInput, optionLabels, optionValues, submitForm, bodyText } from '../fixtures/base';
import { formIds, latestEntry } from '../fixtures/gf';

const ACTIVE_VALUES = ['gfcv_red', 'gfcv_green', 'gfcv_blue'];
const ACTIVE_LABELS = ['Red', 'Green', 'Blue'];
const NONE = '- None -';

// Field ids in the seeded "GFCVTEST Sources" form.
const F = { select: 1, radio: 2, checkbox: 3, multi: 4, required: 5, fromProcessor: 6, radioDefault: 7, checkboxDefault: 8, multiDefault: 9 };

async function radioValues(page: import('@playwright/test').Page, fieldId: number): Promise<string[]> {
  return page.locator(`input[type="radio"][name="input_${fieldId}"]`).evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
}

async function radioLabels(page: import('@playwright/test').Page, formId: number, fieldId: number): Promise<string[]> {
  return page.locator(`#input_${formId}_${fieldId} label`).evaluateAll((els) => els.map((e) => (e.textContent || '').trim()));
}

test.describe('Suite A - CiviCRM Source options', () => {
  test('A-01 a Drop Down lists the option group\'s active values in order, and submits the value', async ({ anonymousPage: page }) => {
    const formId = formIds().sources;
    await page.goto(PAGES.sources);
    const select = gfInput(page, formId, F.select);

    expect((await optionValues(select)).filter((v) => v !== NONE && v !== '')).toEqual(ACTIVE_VALUES);
    expect((await optionLabels(select)).filter((l) => l !== NONE)).toEqual(ACTIVE_LABELS);
    expect(await optionLabels(select)).not.toContain('Purple');

    await select.selectOption('gfcv_red');
    await gfInput(page, formId, F.required).selectOption('gfcv_blue');
    await submitForm(page, formId);

    expect(await bodyText(page)).toContain(SUBMITTED);
    expect(latestEntry(formId)?.[String(F.select)]).toBe('gfcv_red');
  });

  test('A-02 Radio Buttons, Checkboxes and Multi Select offer the same choices', async ({ anonymousPage: page }) => {
    const formId = formIds().sources;
    await page.goto(PAGES.sources);

    expect((await radioValues(page, F.radio)).filter((v) => ACTIVE_VALUES.includes(v))).toEqual(ACTIVE_VALUES);

    const checkboxValues = await page
      .locator(`#input_${formId}_${F.checkbox} input[type="checkbox"]`)
      .evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    expect(checkboxValues).toEqual(ACTIVE_VALUES);

    expect(await optionValues(gfInput(page, formId, F.multi))).toEqual(ACTIVE_VALUES);
  });

  test('A-03 optional Drop Down and Radio Buttons start with "- None -"', async ({ anonymousPage: page }) => {
    const formId = formIds().sources;
    await page.goto(PAGES.sources);

    expect((await optionLabels(gfInput(page, formId, F.select)))[0]).toBe(NONE);
    expect((await radioLabels(page, formId, F.radio))[0]).toBe(NONE);
  });

  test('A-04 required Drop Down, Checkboxes and Multi Select have no "- None -"', async ({ anonymousPage: page }) => {
    const formId = formIds().sources;
    await page.goto(PAGES.sources);

    expect(await optionLabels(gfInput(page, formId, F.required))).not.toContain(NONE);
    expect(await page.locator(`#input_${formId}_${F.checkbox}`).textContent()).not.toContain(NONE);
    expect(await optionLabels(gfInput(page, formId, F.multi))).not.toContain(NONE);
  });

  test('A-05 an optional Drop Down left on "- None -" stores an empty value', async ({ anonymousPage: page }) => {
    const formId = formIds().sources;
    await page.goto(PAGES.sources);

    await gfInput(page, formId, F.select).selectOption({ label: NONE });
    await gfInput(page, formId, F.required).selectOption('gfcv_green');
    await submitForm(page, formId);

    expect(await bodyText(page)).toContain(SUBMITTED);
    expect(latestEntry(formId)?.[String(F.select)]).toBe('');
  });

  test('A-06 a Form Processor input as the source lists its options and stores the value', async ({ anonymousPage: page }) => {
    const formId = formIds().sources;
    await page.goto(PAGES.sources);
    const select = gfInput(page, formId, F.fromProcessor);

    expect((await optionLabels(select)).filter((l) => l !== NONE)).toEqual(ACTIVE_LABELS);

    await select.selectOption('gfcv_blue');
    await gfInput(page, formId, F.required).selectOption('gfcv_red');
    await submitForm(page, formId);

    expect(latestEntry(formId)?.[String(F.fromProcessor)]).toBe('gfcv_blue');
  });

  test('A-07 the option group\'s default value is pre-selected', async ({ anonymousPage: page }) => {
    const formId = formIds().sources;
    await page.goto(PAGES.sources);

    // The required Drop Down has no "- None -" choice competing with the default.
    await expect(gfInput(page, formId, F.required)).toHaveValue('gfcv_green');
  });

  // Known issue (test plan 7.2): pre_render() applies the Default Value to the field's existing
  // choices, then do_civicrm_replacement() rebuilds the choices from CiviCRM and the selection
  // is lost. Marked as an expected failure so it flags as soon as the ordering is fixed.
  test('A-08 Radio Buttons pre-select the choice whose label matches the Default Value', async ({ anonymousPage: page }) => {
    test.fail(true, 'Known issue: the Default Value is applied before the choices are rebuilt from CiviCRM.');
    const formId = formIds().sources;
    await page.goto(PAGES.sources);

    const checked = await page
      .locator(`input[type="radio"][name="input_${F.radioDefault}"]:checked`)
      .evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    expect(checked).toEqual(['gfcv_blue']);
  });

  // Known issue (test plan 7.2): same ordering problem as A-08.
  test('A-09 Checkboxes and Multi Select pre-select every value in a comma-separated Default Value', async ({ anonymousPage: page }) => {
    test.fail(true, 'Known issue: the Default Value is applied before the choices are rebuilt from CiviCRM.');
    const formId = formIds().sources;
    await page.goto(PAGES.sources);

    const checked = await page
      .locator(`#input_${formId}_${F.checkboxDefault} input[type="checkbox"]:checked`)
      .evaluateAll((els) => els.map((e) => (e as HTMLInputElement).value));
    expect(checked).toEqual(['gfcv_red', 'gfcv_blue']);

    const selected = await gfInput(page, formId, F.multiDefault)
      .locator('option:checked')
      .evaluateAll((els) => els.map((e) => (e as HTMLOptionElement).value));
    expect(selected).toEqual(['gfcv_red', 'gfcv_blue']);
  });
});
