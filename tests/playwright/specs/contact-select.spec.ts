/**
 * Suite C - CiviCRM Group Contact Select.
 *
 * A Drop Down of the contacts in a CiviCRM group or saved search. A submitted value must be
 * one of the contacts that was offered: C-04 changes an option's value in the browser, as a
 * modified POST would, and expects the submission to be rejected.
 *
 * The field lists only the contacts the person viewing the form may see in CiviCRM.
 * seed-data.php gives logged-in users (the Authenticated ACL role) View on the test group and on
 * a smart group made from the saved search, and gives everybody View on the public group. The
 * restricted group is visible only to a custom ACL role that the member holds and the other
 * member does not. C-01 to C-07 therefore run as the member.
 */

import { test, expect, PAGES, SUBMITTED, gfInput, optionLabels, optionValues, setOptionValue, submitForm, bodyText } from '../fixtures/base';
import { civiApi4First } from '../fixtures/civi';
import { entryCount, formIds, latestEntry, withFieldProperties, withOption } from '../fixtures/gf';
import { seededIds } from '../fixtures/ids';

const INVALID = 'Invalid selection. Please select from the available choices.';

// Field ids in the seeded "GFCVTEST Contact Select" form.
const F = { group: 1, savedSearch: 2, emptyGroup: 3 };

test.describe('Suite C - CiviCRM Group Contact Select', () => {
  test('C-01 a group source lists its contacts by sort name, without deleted contacts', async ({ memberPage: page }) => {
    const { groupContactIds, deletedGroupContactId } = seededIds();
    await page.goto(PAGES.contactSelect);
    const select = gfInput(page, formIds().contactSelect, F.group);

    expect(await optionLabels(select)).toEqual(['Groupone, Gfcvtest', 'Grouptwo, Gfcvtest']);
    expect(await optionValues(select)).toEqual(groupContactIds.map(String));
    expect(await optionValues(select)).not.toContain(String(deletedGroupContactId));
  });

  test('C-02 a saved search source lists the saved search\'s contacts', async ({ memberPage: page }) => {
    const { savedSearchContactIds } = seededIds();
    await page.goto(PAGES.contactSelect);

    expect(await optionValues(gfInput(page, formIds().contactSelect, F.savedSearch))).toEqual(savedSearchContactIds.map(String));
  });

  test('C-03 an offered contact is accepted and stored as the contact ID', async ({ memberPage: page }) => {
    const { groupContactIds } = seededIds();
    const formId = formIds().contactSelect;
    await page.goto(PAGES.contactSelect);

    await gfInput(page, formId, F.group).selectOption(String(groupContactIds[1]));
    await submitForm(page, formId);

    expect(await bodyText(page)).toContain(SUBMITTED);
    expect(latestEntry(formId)?.[String(F.group)]).toBe(String(groupContactIds[1]));
  });

  test('C-04 a contact outside the group is rejected', async ({ memberPage: page }) => {
    const { outsideGroupContactId } = seededIds();
    const formId = formIds().contactSelect;
    const before = entryCount(formId);
    await page.goto(PAGES.contactSelect);

    await setOptionValue(gfInput(page, formId, F.group), 0, String(outsideGroupContactId));
    await submitForm(page, formId);

    await expect(page.locator(`#field_${formId}_${F.group}`)).toContainText(INVALID);
    expect(entryCount(formId)).toBe(before);
  });

  test('C-05 a required field submitted with no contact shows the required message', async ({ memberPage: page }) => {
    const formId = formIds().contactSelectRequired;
    await page.goto(PAGES.contactSelectRequired);

    await setOptionValue(gfInput(page, formId, 1), 0, '');
    await submitForm(page, formId);

    await expect(page.locator(`#field_${formId}_1`)).toContainText('This field is required.');
  });

  test('C-06 an optional field submitted with no contact is accepted', async ({ memberPage: page }) => {
    const formId = formIds().contactSelect;
    await page.goto(PAGES.contactSelect);

    await setOptionValue(gfInput(page, formId, F.group), 0, '');
    await submitForm(page, formId);

    expect(await bodyText(page)).toContain(SUBMITTED);
    expect(latestEntry(formId)?.[String(F.group)]).toBe('');
  });

  test('C-07 after a rejected submission each contact is listed once', async ({ memberPage: page }) => {
    const { outsideGroupContactId, groupContactIds } = seededIds();
    const formId = formIds().contactSelect;
    await page.goto(PAGES.contactSelect);

    await setOptionValue(gfInput(page, formId, F.group), 0, String(outsideGroupContactId));
    await submitForm(page, formId);

    expect(await optionValues(gfInput(page, formId, F.group))).toEqual(groupContactIds.map(String));
  });

  test('C-08 an empty group offers only "No Contacts in this Group"', async ({ anonymousPage: page }) => {
    await page.goto(PAGES.contactSelect);

    expect(await optionLabels(gfInput(page, formIds().contactSelect, F.emptyGroup))).toEqual(['No Contacts in this Group']);
  });

  test('C-09 a deleted or non-Contact saved search offers only "No Contacts in this Group"', async ({ anonymousPage: page }) => {
    const formId = formIds().contactSelect;
    const nonContact = civiApi4First<{ id: number }>('SavedSearch.get', { select: ['id'], where: [['api_entity', '!=', 'Contact']] });

    for (const source of ['ss:999999', `ss:${nonContact.id}`]) {
      await withFieldProperties(formId, F.savedSearch, { civicrm_group: source }, async () => {
        await page.goto(PAGES.contactSelect);

        expect(await optionLabels(gfInput(page, formId, F.savedSearch)), source).toEqual(['No Contacts in this Group']);
      });
    }
  });

  test('C-10 an anonymous visitor without an ACL is offered no contacts', async ({ anonymousPage: page }) => {
    const formId = formIds().contactSelect;
    await page.goto(PAGES.contactSelect);

    expect(await optionLabels(gfInput(page, formId, F.group))).toEqual(['No Contacts in this Group']);
    expect(await optionLabels(gfInput(page, formId, F.savedSearch))).toEqual(['No Contacts in this Group']);
  });

  test('C-11 a group shared with an ACL role is listed to that role and to users who may view all contacts', async ({ memberPage, otherMemberPage, adminPage }) => {
    const { restrictedGroupId, restrictedGroupContactIds } = seededIds();
    const formId = formIds().contactSelect;

    await withFieldProperties(formId, F.group, { civicrm_group: String(restrictedGroupId) }, async () => {
      // The member holds the role the group's ACL is for.
      await memberPage.goto(PAGES.contactSelect);
      expect(await optionValues(gfInput(memberPage, formId, F.group))).toEqual(restrictedGroupContactIds.map(String));

      // The other member is logged in too, but does not hold the role.
      await otherMemberPage.goto(PAGES.contactSelect);
      expect(await optionLabels(gfInput(otherMemberPage, formId, F.group))).toEqual(['No Contacts in this Group']);

      await adminPage.goto(PAGES.contactSelect);
      expect(await optionValues(gfInput(adminPage, formId, F.group))).toEqual(restrictedGroupContactIds.map(String));
    });
  });

  test('C-12 an ACL for everybody lists the group to an anonymous visitor', async ({ anonymousPage: page }) => {
    const { publicGroupId, publicGroupContactIds } = seededIds();
    const formId = formIds().contactSelect;

    await withFieldProperties(formId, F.group, { civicrm_group: String(publicGroupId) }, async () => {
      await page.goto(PAGES.contactSelect);
      expect(await optionValues(gfInput(page, formId, F.group))).toEqual(publicGroupContactIds.map(String));
    });
  });

  test('C-13 with the permission checks turned off, an anonymous visitor is offered every contact', async ({ anonymousPage: page }) => {
    const { groupContactIds, savedSearchContactIds } = seededIds();
    const formId = formIds().contactSelect;

    await withOption('gfcvtest_contact_select_unchecked', 1, async () => {
      await page.goto(PAGES.contactSelect);
      expect(await optionValues(gfInput(page, formId, F.group))).toEqual(groupContactIds.map(String));
      expect(await optionValues(gfInput(page, formId, F.savedSearch))).toEqual(savedSearchContactIds.map(String));
    });
  });

  test('C-14 each contact is listed only to the users who may see that contact', async ({ memberPage, otherMemberPage }) => {
    const { mixedGroupId, groupContactIds, restrictedGroupContactIds } = seededIds();
    const formId = formIds().contactSelect;
    const [groupone] = groupContactIds;
    const [restrictedone] = restrictedGroupContactIds;

    await withFieldProperties(formId, F.group, { civicrm_group: String(mixedGroupId) }, async () => {
      await memberPage.goto(PAGES.contactSelect);
      expect(await optionValues(gfInput(memberPage, formId, F.group))).toEqual([groupone, restrictedone].map(String));

      await otherMemberPage.goto(PAGES.contactSelect);
      expect(await optionValues(gfInput(otherMemberPage, formId, F.group))).toEqual([String(groupone)]);
    });
  });

  test('C-15 a contact the user may not see is rejected when submitted', async ({ otherMemberPage: page }) => {
    const { restrictedGroupId, restrictedGroupContactIds } = seededIds();
    const formId = formIds().contactSelect;
    const before = entryCount(formId);

    await withFieldProperties(formId, F.group, { civicrm_group: String(restrictedGroupId) }, async () => {
      await page.goto(PAGES.contactSelect);

      // As a modified POST would: offer a contact of the group that this user may not see.
      await setOptionValue(gfInput(page, formId, F.group), 0, String(restrictedGroupContactIds[0]));
      await submitForm(page, formId);

      await expect(page.locator(`#field_${formId}_${F.group}`)).toContainText(INVALID);
      expect(entryCount(formId)).toBe(before);
    });
  });
});
