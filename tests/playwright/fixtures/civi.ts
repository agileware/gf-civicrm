import { execFileSync } from 'node:child_process';

/**
 * Shells out to `cv` and `wp` to read and write CiviCRM and WordPress data directly,
 * bypassing the browser.
 *
 * Most of what this plugin does is only visible after the fact - in the Gravity Forms entry,
 * in CiviCRM, or in what a webhook actually sent - so a page result only means something if
 * the data agrees. These helpers make those data-level assertions possible.
 *
 * CIVI_EXEC_PREFIX (set by the CI workflow to a `docker exec ...` command targeting the
 * WordPress container) is prepended to every call. Locally under ddev, set it to
 * `ddev exec --dir /var/www/html/wp-content/plugins/gf-civicrm`.
 */
function execPrefixParts(): string[] {
  const prefix = process.env.CIVI_EXEC_PREFIX;
  return prefix ? prefix.split(' ').filter(Boolean) : [];
}

function run(args: string[]): string {
  const all = [...execPrefixParts(), ...args];
  const [cmd, ...rest] = all;
  return execFileSync(cmd, rest, { encoding: 'utf-8', maxBuffer: 32 * 1024 * 1024 });
}

export function civiApi4<T = any>(entityDotAction: string, params: Record<string, unknown> = {}): T {
  return JSON.parse(run(['cv', 'api4', entityDotAction, JSON.stringify(params)]));
}

export function civiApi3<T = any>(entity: string, action: string, params: Record<string, unknown> = {}): T {
  return JSON.parse(run(['cv', 'api3', `${entity}.${action}`, JSON.stringify(params)]));
}

export function civiApi4First<T = any>(entityDotAction: string, params: Record<string, unknown> = {}): T {
  const rows = civiApi4<T[]>(entityDotAction, params);
  if (!rows.length) {
    throw new Error(`${entityDotAction} returned no rows for ${JSON.stringify(params)}`);
  }
  return rows[0];
}

/** Evaluate PHP in CiviCRM's context (`cv ev`) and return its output. */
export function cvEval(php: string): string {
  return run(['cv', 'ev', php]).trim();
}

/** Run a wp-cli command in the WordPress container, e.g. wpCli(['transient', 'delete', '--all']). */
export function wpCli(args: string[]): string {
  return run(['wp', ...args]);
}

const START = '@@GFCVTEST_JSON@@';
const END = '@@GFCVTEST_END@@';

/**
 * Evaluate a PHP function body in WordPress and return its JSON-decoded return value, e.g.
 * wpEvalJson('return get_option("gfcvtest_forms");').
 *
 * The result is wrapped in markers so that a stray PHP notice printed by some other plugin
 * can never corrupt the parse.
 */
export function wpEvalJson<T = any>(body: string): T {
  const out = run(['wp', 'eval', `echo '${START}' . wp_json_encode( ( function () { ${body} } )() ) . '${END}';`]);
  const start = out.indexOf(START);
  const end = out.indexOf(END);
  if (start === -1 || end === -1) {
    throw new Error(`wp eval produced no result. Output was:\n${out}`);
  }
  return JSON.parse(out.slice(start + START.length, end));
}
