import { deleteOption, getOption } from './gf';

/**
 * Output captured by the test-only must-use plugin (mu-plugins/gfcv-test-helpers.php):
 * mail passed to wp_mail, and the last request received by the capture endpoint.
 */

export type CapturedMail = { to: string | string[]; subject: string; message: string };

export function capturedMail(): CapturedMail[] {
  return getOption<CapturedMail[] | false>('gfcvtest_mail') || [];
}

export function clearMail(): void {
  deleteOption('gfcvtest_mail');
}

export type CapturedRequest = {
  query: Record<string, string>;
  /** WordPress lowercases header names and underscores them: x-civi-key becomes x_civi_key. */
  headers: Record<string, string[]>;
  body: string;
};

export function capturedRequest(): CapturedRequest | null {
  return getOption<CapturedRequest | false>('gfcvtest_capture') || null;
}

export function clearCapturedRequest(): void {
  deleteOption('gfcvtest_capture');
}
