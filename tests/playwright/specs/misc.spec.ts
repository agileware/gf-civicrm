/**
 * Suite L - Miscellaneous.
 *
 * L-02 (activation with neither CiviCRM nor CMRF active) needs a broken environment and is
 * verified manually (test plan section 7.1).
 */

import { test, expect, PAGES } from '../fixtures/base';
import { formIds } from '../fixtures/gf';

test.describe('Suite L - Miscellaneous', () => {
  // Known issue (test plan 7.2): the cap is applied through the gform_counter_script filter,
  // which Gravity Forms 3.0 stopped calling when it moved counters into element attributes
  // (GFFormDisplay::get_counter_init_script() is deprecated). The cap has no effect on GF 3.x.
  test('L-01 a text field\'s character counter is capped at 255, CiviCRM\'s limit', async ({ anonymousPage: page }) => {
    test.fail(true, 'Known issue: gform_counter_script is no longer called by Gravity Forms 3.x.');
    await page.goto(PAGES.counter);

    await expect(page.locator(`#field_${formIds().counter}_1 .ginput_counter`)).toContainText('of 255 max characters');
  });
});
