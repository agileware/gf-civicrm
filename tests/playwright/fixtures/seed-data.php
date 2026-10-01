<?php
/**
 * Seeds the CiviCRM records the Playwright suite asserts against.
 *
 * Run with `cv scr`, after the WordPress users exist (setup-environment.sh creates them
 * first, because each CiviCRM contact here is linked to one via UFMatch).
 *
 * Idempotent: every record is looked up by a recognisable "GFCVTEST" name before being
 * created, so re-running the script updates rather than duplicating. Nothing here relies
 * on incidental data that happens to be in the database.
 */

use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Country;
use Civi\Api4\Email;
use Civi\Api4\Group;
use Civi\Api4\GroupContact;
use Civi\Api4\Membership;
use Civi\Api4\MembershipType;
use Civi\Api4\OptionGroup;
use Civi\Api4\OptionValue;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use Civi\Api4\SavedSearch;
use Civi\Api4\StateProvince;
use Civi\Api4\UFMatch;

const PREFIX = 'GFCVTEST';

$users = json_decode(file_get_contents(__DIR__ . '/test-users.json'), TRUE);

function out(string $msg): void {
  echo $msg . "\n";
}

/**
 * Find or create an Individual for a WordPress user, and give it a primary email.
 *
 * CiviCRM creates a contact and UFMatch row automatically when a WordPress user registers,
 * so an existing UFMatch row wins: that contact is adopted and renamed, rather than a second
 * contact being created alongside it.
 */
function ensureUserContact(array $spec): int {
  $wpUser = get_user_by('login', $spec['username']);
  if (!$wpUser) {
    throw new RuntimeException("WordPress user {$spec['username']} does not exist - run setup-environment.sh first.");
  }

  $match = UFMatch::get(FALSE)->addWhere('uf_id', '=', $wpUser->ID)->execute()->first();
  $contactId = $match ? (int) $match['contact_id'] : ensureContact($spec['firstName'], $spec['lastName']);

  Contact::update(FALSE)
    ->addWhere('id', '=', $contactId)
    ->addValue('first_name', $spec['firstName'])
    ->addValue('last_name', $spec['lastName'])
    ->addValue('is_deleted', FALSE)
    ->execute();

  if (!$match) {
    UFMatch::create(FALSE)
      ->addValue('uf_id', $wpUser->ID)
      ->addValue('uf_name', $spec['username'])
      ->addValue('contact_id', $contactId)
      ->addValue('domain_id', CRM_Core_Config::domainID())
      ->execute();
  }

  $email = Email::get(FALSE)
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('email', '=', $spec['email'])
    ->execute()->first();
  if (!$email) {
    Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', $spec['email'])
      ->addValue('is_primary', TRUE)
      ->addValue('location_type_id', 1)
      ->execute();
  }

  return $contactId;
}

/** Find-or-create a plain Individual by name. */
function ensureContact(string $first, string $last): int {
  $existing = Contact::get(FALSE)
    ->addWhere('first_name', '=', $first)
    ->addWhere('last_name', '=', $last)
    ->execute()->first();
  if ($existing) {
    Contact::update(FALSE)->addWhere('id', '=', $existing['id'])->addValue('is_deleted', FALSE)->execute();
    return (int) $existing['id'];
  }
  return (int) Contact::create(FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', $first)
    ->addValue('last_name', $last)
    ->execute()->first()['id'];
}

function ensureGroup(string $name, string $title): int {
  $existing = Group::get(FALSE)->addWhere('name', '=', $name)->execute()->first();
  if ($existing) {
    return (int) $existing['id'];
  }
  return (int) Group::create(FALSE)
    ->addValue('name', $name)
    ->addValue('title', $title)
    ->addValue('is_active', TRUE)
    ->execute()->first()['id'];
}

function ensureInGroup(int $groupId, int $contactId): void {
  $existing = GroupContact::get(FALSE)
    ->addWhere('group_id', '=', $groupId)
    ->addWhere('contact_id', '=', $contactId)
    ->execute()->first();
  if (!$existing) {
    GroupContact::create(FALSE)
      ->addValue('group_id', $groupId)
      ->addValue('contact_id', $contactId)
      ->addValue('status', 'Added')
      ->execute();
  }
}

// ---------------------------------------------------------------- contacts

out('Seeding contacts...');
$contactIds = [];
foreach ($users as $key => $spec) {
  $contactIds[$key] = ensureUserContact($spec);
}

// The API user authenticates webhook requests and the CMRF profile with this key.
Contact::update(FALSE)
  ->addWhere('id', '=', $contactIds['api'])
  ->addValue('api_key', $users['api']['apiKey'])
  ->execute();

// ---------------------------------------------------------------- group and saved search

out('Seeding the contact group and saved search...');
$groupId = ensureGroup('gfcvtest_group', PREFIX . ' Group');
foreach (['Groupone', 'Grouptwo', 'Groupdeleted'] as $last) {
  ensureInGroup($groupId, ensureContact('Gfcvtest', $last));
}
// A deleted contact still in the group must not be offered.
Contact::update(FALSE)
  ->addWhere('first_name', '=', 'Gfcvtest')
  ->addWhere('last_name', '=', 'Groupdeleted')
  ->addValue('is_deleted', TRUE)
  ->execute();

ensureGroup('gfcvtest_empty_group', PREFIX . ' Empty Group');

foreach (['Searchone', 'Searchtwo'] as $last) {
  ensureContact('Gfcvtest', $last);
}
$savedSearch = SavedSearch::get(FALSE)->addWhere('name', '=', 'gfcvtest_saved_search')->execute()->first();
$savedSearchValues = [
  'name' => 'gfcvtest_saved_search',
  'label' => PREFIX . ' Saved Search',
  'api_entity' => 'Contact',
  'api_params' => [
    'version' => 4,
    'select' => ['id', 'sort_name'],
    'where' => [['last_name', 'IN', ['Searchone', 'Searchtwo']], ['is_deleted', '=', FALSE]],
  ],
];
if ($savedSearch) {
  SavedSearch::update(FALSE)->addWhere('id', '=', $savedSearch['id'])->setValues($savedSearchValues)->execute();
}
else {
  SavedSearch::create(FALSE)->setValues($savedSearchValues)->execute();
}

// ---------------------------------------------------------------- option group

out('Seeding the colours option group...');
$optionGroup = OptionGroup::get(FALSE)->addWhere('name', '=', 'gfcvtest_colours')->execute()->first();
$optionGroupId = $optionGroup ? (int) $optionGroup['id'] : (int) OptionGroup::create(FALSE)
  ->addValue('name', 'gfcvtest_colours')
  ->addValue('title', PREFIX . ' Colours')
  ->addValue('is_active', TRUE)
  ->execute()->first()['id'];

// Values differ from labels, so tests can tell which one was submitted.
$colours = [
  ['value' => 'gfcv_red', 'label' => 'Red', 'weight' => 1, 'is_default' => FALSE, 'is_active' => TRUE],
  ['value' => 'gfcv_green', 'label' => 'Green', 'weight' => 2, 'is_default' => TRUE, 'is_active' => TRUE],
  ['value' => 'gfcv_blue', 'label' => 'Blue', 'weight' => 3, 'is_default' => FALSE, 'is_active' => TRUE],
  ['value' => 'gfcv_purple', 'label' => 'Purple', 'weight' => 4, 'is_default' => FALSE, 'is_active' => FALSE],
];
foreach ($colours as $colour) {
  $existing = OptionValue::get(FALSE)
    ->addWhere('option_group_id', '=', $optionGroupId)
    ->addWhere('value', '=', $colour['value'])
    ->execute()->first();
  $values = $colour + ['option_group_id' => $optionGroupId, 'name' => $colour['value']];
  unset($values['is_default']);
  if ($existing) {
    OptionValue::update(FALSE)->addWhere('id', '=', $existing['id'])->setValues($values)->execute();
  }
  else {
    OptionValue::create(FALSE)->setValues($values)->execute();
  }
}
// Mark the default in its own update, after every value is saved. CiviCRM clears the group's
// default whenever a value is saved with is_default set - even to FALSE - so is_default is
// only ever written as TRUE, for the one default value.
foreach (array_filter($colours, fn($colour) => $colour['is_default']) as $colour) {
  OptionValue::update(FALSE)
    ->addWhere('option_group_id', '=', $optionGroupId)
    ->addWhere('value', '=', $colour['value'])
    ->addValue('is_default', TRUE)
    ->execute();
}

// ---------------------------------------------------------------- payment tokens

out('Seeding the payment processor, recurring contributions and tokens...');
$processor = PaymentProcessor::get(FALSE)
  ->addWhere('name', '=', 'gfcvtest_dummy')
  ->addWhere('is_test', '=', FALSE)
  ->execute()->first();
if ($processor) {
  $processorId = (int) $processor['id'];
}
else {
  // api3 fills in the processor type's defaults (class name, URLs) and creates the test twin.
  $processorId = (int) civicrm_api3('PaymentProcessor', 'create', [
    'name' => 'gfcvtest_dummy',
    'title' => PREFIX . ' Dummy',
    'payment_processor_type_id' => 'Dummy',
    'is_active' => 1,
    'is_test' => 0,
    'domain_id' => CRM_Core_Config::domainID(),
    'user_name' => 'dummy',
  ])['id'];
}

$org = Contact::get(FALSE)->addWhere('organization_name', '=', PREFIX . ' Org')->execute()->first();
$orgId = $org ? (int) $org['id'] : (int) Contact::create(FALSE)
  ->addValue('contact_type', 'Organization')
  ->addValue('organization_name', PREFIX . ' Org')
  ->execute()->first()['id'];

$membershipType = MembershipType::get(FALSE)->addWhere('name', '=', PREFIX . ' Membership')->execute()->first();
$membershipTypeId = $membershipType ? (int) $membershipType['id'] : (int) MembershipType::create(FALSE)
  ->addValue('name', PREFIX . ' Membership')
  ->addValue('member_of_contact_id', $orgId)
  ->addValue('financial_type_id:name', 'Member Dues')
  ->addValue('duration_unit', 'year')
  ->addValue('duration_interval', 1)
  ->addValue('period_type', 'rolling')
  ->addValue('minimum_fee', 10)
  ->execute()->first()['id'];

/**
 * A token with a recurring contribution in the given status. The token's masked number is
 * the identity used throughout the specs (the field shows its last four digits).
 */
function ensureToken(int $contactId, int $processorId, string $masked, string $expiry, string $status, float $amount, string $unit, ?int $membershipTypeId = NULL): int {
  $token = PaymentToken::get(FALSE)
    ->addWhere('contact_id', '=', $contactId)
    ->addWhere('masked_account_number', '=', $masked)
    ->execute()->first();
  $tokenId = $token ? (int) $token['id'] : (int) PaymentToken::create(FALSE)
    ->addValue('contact_id', $contactId)
    ->addValue('payment_processor_id', $processorId)
    ->addValue('token', 'gfcvtest-token-' . $masked)
    ->addValue('masked_account_number', $masked)
    ->addValue('expiry_date', $expiry)
    ->execute()->first()['id'];

  $recur = ContributionRecur::get(FALSE)->addWhere('payment_token_id', '=', $tokenId)->execute()->first();
  $recurValues = [
    'contact_id' => $contactId,
    'amount' => $amount,
    'currency' => 'AUD',
    'frequency_unit' => $unit,
    'frequency_interval' => 1,
    'contribution_status_id:name' => $status,
    'payment_processor_id' => $processorId,
    'payment_token_id' => $tokenId,
    'financial_type_id:name' => 'Donation',
    'start_date' => 'now',
  ];
  if ($recur) {
    $recurId = (int) $recur['id'];
    ContributionRecur::update(FALSE)->addWhere('id', '=', $recurId)->setValues($recurValues)->execute();
  }
  else {
    $recurId = (int) ContributionRecur::create(FALSE)->setValues($recurValues)->execute()->first()['id'];
  }

  if ($membershipTypeId) {
    $membership = Membership::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('membership_type_id', '=', $membershipTypeId)
      ->execute()->first();
    if (!$membership) {
      Membership::create(FALSE)
        ->addValue('contact_id', $contactId)
        ->addValue('membership_type_id', $membershipTypeId)
        ->addValue('contribution_recur_id', $recurId)
        ->addValue('join_date', 'now')
        ->addValue('start_date', 'now')
        ->execute();
    }
  }

  return $tokenId;
}

// Member: two listable tokens (one with a membership) and one whose recur is Completed.
ensureToken($contactIds['member'], $processorId, '4111111111111111', '2030-12-31', 'In Progress', 10, 'month', $membershipTypeId);
ensureToken($contactIds['member'], $processorId, '5222222222222222', '2031-06-30', 'Failing', 20, 'year');
ensureToken($contactIds['member'], $processorId, '6333333333333333', '2032-01-31', 'Completed', 30, 'month');
// Other member: tokens the member must never see or submit.
ensureToken($contactIds['otherMember'], $processorId, '7444444444444444', '2030-11-30', 'In Progress', 40, 'month');

// ---------------------------------------------------------------- countries

out('Limiting the available countries...');
$au = Country::get(FALSE)->addWhere('iso_code', '=', 'AU')->execute()->single();
$nz = Country::get(FALSE)->addWhere('iso_code', '=', 'NZ')->execute()->single();
$us = Country::get(FALSE)->addWhere('iso_code', '=', 'US')->execute()->single();

// Pick a country CiviCRM holds no states or provinces for, for the "state not required" case.
$stateless = NULL;
foreach (['MC', 'SG', 'VA', 'LI', 'AD', 'SM'] as $iso) {
  $country = Country::get(FALSE)->addWhere('iso_code', '=', $iso)->execute()->first();
  if ($country && !StateProvince::get(FALSE)->selectRowCount()->addWhere('country_id', '=', $country['id'])->execute()->countMatched()) {
    $stateless = $country;
    break;
  }
}
if (!$stateless) {
  throw new RuntimeException('Could not find a country without states or provinces.');
}

Civi::settings()->set('countryLimit', [$au['id'], $nz['id'], $us['id'], $stateless['id']]);
Civi::settings()->set('defaultContactCountry', $au['id']);

out("Seed complete. Stateless country: {$stateless['iso_code']}");
