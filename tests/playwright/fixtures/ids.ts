import { civiApi4, civiApi4First, cvEval } from './civi';
import users from './test-users.json';

/**
 * Resolves the ids of the records seed-data.php created, from CiviCRM itself, so specs never
 * hardcode an id that differs between environments.
 */

export type Token = { id: number; last4: string; contactId: number };

export type SeededIds = {
  memberContactId: number;
  otherContactId: number;
  /**
   * The member's tokens whose recurring contribution is In Progress or Failing, in the order
   * the field lists them: latest expiry first.
   */
  memberListedTokens: Token[];
  /** The member's token whose recurring contribution is Completed: never listed. */
  memberCompletedToken: Token;
  otherMemberToken: Token;
  groupContactIds: number[];
  deletedGroupContactId: number;
  outsideGroupContactId: number;
  savedSearchContactIds: number[];
  statelessCountryIso: string;
};

let cached: SeededIds | null = null;

function contactByName(last: string, deleted = false): number {
  // APIv4 Contact.get silently leaves out deleted contacts unless the query filters on
  // is_deleted itself, so the filter is always explicit.
  const where: unknown[] = [
    ['first_name', '=', 'Gfcvtest'],
    ['last_name', '=', last],
    ['is_deleted', '=', deleted],
  ];
  return civiApi4First<{ id: number }>('Contact.get', { select: ['id'], where, checkPermissions: false }).id;
}

function tokenFor(contactId: number, last4: string): Token {
  const row = civiApi4First<{ id: number }>('PaymentToken.get', {
    select: ['id'],
    where: [
      ['contact_id', '=', contactId],
      ['masked_account_number', 'LIKE', `%${last4}`],
    ],
    checkPermissions: false,
  });
  return { id: row.id, last4, contactId };
}

export function seededIds(): SeededIds {
  if (cached) {
    return cached;
  }

  const memberContactId = contactByName(users.member.lastName);
  const otherContactId = contactByName(users.otherMember.lastName);

  const limit = civiApi4<Array<{ value: number[] }>>('Setting.get', { select: ['countryLimit'] })[0].value || [];
  const countries = civiApi4<Array<{ id: number; iso_code: string }>>('Country.get', {
    select: ['id', 'iso_code'],
    where: [['id', 'IN', limit]],
  });
  const stateless = countries.find(
    (c) => civiApi4<unknown[]>('StateProvince.get', { select: ['id'], where: [['country_id', '=', c.id]], limit: 1 }).length === 0
  );
  if (!stateless) {
    throw new Error('No country without states found in countryLimit - has seed-data.php run?');
  }

  cached = {
    memberContactId,
    otherContactId,
    // *2222 expires 06/2031 and *1111 expires 12/2030 (see seed-data.php).
    memberListedTokens: [tokenFor(memberContactId, '2222'), tokenFor(memberContactId, '1111')],
    memberCompletedToken: tokenFor(memberContactId, '3333'),
    otherMemberToken: tokenFor(otherContactId, '4444'),
    groupContactIds: [contactByName('Groupone'), contactByName('Grouptwo')],
    deletedGroupContactId: contactByName('Groupdeleted', true),
    outsideGroupContactId: contactByName('Searchone'),
    savedSearchContactIds: [contactByName('Searchone'), contactByName('Searchtwo')],
    statelessCountryIso: stateless.iso_code,
  };
  return cached;
}

/** A valid checksum for a contact, as a CiviCRM "{contact.checksum}" link would carry. */
export function checksumFor(contactId: number): string {
  return cvEval(`echo CRM_Contact_BAO_Contact_Utils::generateChecksum(${Number(contactId)});`);
}
