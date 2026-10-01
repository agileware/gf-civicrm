import { wpEvalJson } from './civi';

/**
 * Gravity Forms data, read and written through `wp eval`, so specs can assert on what was
 * stored rather than only on what the page showed.
 */

export type FormIds = {
  sources: number;
  defaults: number;
  contactSelect: number;
  contactSelectRequired: number;
  paymentToken: number;
  paymentTokenRequired: number;
  address: number;
  webhook: number;
  webhookFail: number;
  mergeTags: number;
  counter: number;
};

let formIdsCache: FormIds | null = null;

/** Seeded form ids, keyed by the short names seed-forms.php records in gfcvtest_forms. */
export function formIds(): FormIds {
  if (!formIdsCache) {
    const ids = wpEvalJson<FormIds | false>('return get_option( "gfcvtest_forms" );');
    if (!ids) {
      throw new Error('No seeded forms found. Run fixtures/setup-environment.sh (the CI workflow does this via SETUP_SCRIPT).');
    }
    formIdsCache = ids;
  }
  return formIdsCache;
}

export type Entry = Record<string, any> & { id: string; form_id: string };

export function entryCount(formId: number): number {
  return wpEvalJson<number>(`return (int) GFAPI::count_entries( ${formId} );`);
}

export function latestEntry(formId: number): Entry | null {
  return wpEvalJson<Entry | null>(
    `$e = GFAPI::get_entries( ${formId}, [], [ 'key' => 'id', 'direction' => 'DESC' ], [ 'page_size' => 1 ] );
     return $e ? $e[0] : null;`
  );
}

export function entryMeta<T = any>(entryId: number | string, key: string): T {
  return wpEvalJson<T>(`return gform_get_meta( ${Number(entryId)}, ${JSON.stringify(key)} );`);
}

export function deleteEntries(formId: number): void {
  wpEvalJson(`foreach ( GFAPI::get_entries( ${formId}, [], null, [ 'page_size' => 200 ] ) as $e ) { GFAPI::delete_entry( $e['id'] ); } return true;`);
}

export type Feed = { id: string; form_id: string; is_active: string; meta: Record<string, any>; addon_slug: string };

export function feedsForForm(formId: number): Feed[] {
  return wpEvalJson<Feed[]>(`$f = GFAPI::get_feeds( null, ${formId}, null, null ); return is_wp_error( $f ) ? [] : $f;`);
}

export function feed(feedId: number | string): Feed {
  return wpEvalJson<Feed>(`return GFAPI::get_feed( ${Number(feedId)} );`);
}

export function setFeedUrl(feedId: number | string, url: string): void {
  wpEvalJson(
    `$feed = GFAPI::get_feed( ${Number(feedId)} );
     $feed['meta']['requestURL'] = ${JSON.stringify(url)};
     GFAPI::update_feed( ${Number(feedId)}, $feed['meta'] );
     return true;`
  );
}

export type PluginSettings = Record<string, any>;

export function pluginSettings(): PluginSettings {
  return wpEvalJson<PluginSettings>('return GFCiviCRM\\FieldsAddOn::get_instance()->get_plugin_settings();');
}

export function updatePluginSettings(changes: PluginSettings): void {
  wpEvalJson(
    `$addon = GFCiviCRM\\FieldsAddOn::get_instance();
     $addon->update_plugin_settings( array_merge( $addon->get_plugin_settings() ?: [], json_decode( ${JSON.stringify(JSON.stringify(changes))}, true ) ) );
     return true;`
  );
}

/**
 * Change plugin settings for the duration of `fn`, then restore them - even if `fn` throws -
 * so spec ordering can never matter.
 */
export async function withPluginSettings<T>(changes: PluginSettings, fn: () => Promise<T>): Promise<T> {
  const before = pluginSettings();
  updatePluginSettings(changes);
  try {
    return await fn();
  } finally {
    wpEvalJson(
      `GFCiviCRM\\FieldsAddOn::get_instance()->update_plugin_settings( json_decode( ${JSON.stringify(JSON.stringify(before))}, true ) );
       return true;`
    );
  }
}

export function getOption<T = any>(name: string): T {
  return wpEvalJson<T>(`return get_option( ${JSON.stringify(name)} );`);
}

export function deleteOption(name: string): void {
  wpEvalJson(`return delete_option( ${JSON.stringify(name)} );`);
}
