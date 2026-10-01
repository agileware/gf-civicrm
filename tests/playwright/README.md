# Playwright frontend tests

End-to-end tests for the Gravity Forms CiviCRM Integration plugin, run against a live
WordPress + CiviCRM + Gravity Forms site. They follow the pattern of the WP CiviCRM UX suite.

The test **environment** is centrally managed in
[agileware/ci-workflows](https://github.com/agileware/ci-workflows)
(`.github/workflows/playwright-tests.yml`); the **tests** live here and are extended
alongside the plugin. `.github/workflows/frontend-tests.yml` wires the two together and runs
on every push to `main`, every pull request, and on demand. The test plan is the "GF CiviCRM —
Frontend Test Plan" document; test IDs (A-01, D-09, …) match it.

## Layout

| Path | Purpose |
|---|---|
| `specs/` | One spec file per suite of the test plan |
| `fixtures/base.ts` | Logged-in page fixtures per role, page slugs, form helpers |
| `fixtures/civi.ts` | `cv` and `wp` helpers for data-level assertions |
| `fixtures/gf.ts` | Gravity Forms entries, entry meta, feeds and plugin settings |
| `fixtures/capture.ts` | Mail and webhook requests captured by the test-only must-use plugin |
| `fixtures/ids.ts` | Resolves the seeded records' ids from CiviCRM |
| `fixtures/install-dependencies.sh` | Gravity Forms and the Webhooks add-on, before the plugin is activated (`PRE_ACTIVATE_SCRIPT`) |
| `fixtures/setup-environment.sh` | Roles, users, extensions, Form Processors, seed data, forms, settings (`SETUP_SCRIPT`) |
| `fixtures/seed-data.php` | CiviCRM records, run through `cv scr` |
| `fixtures/seed-forms.php` | Gravity Forms forms, Webhooks feeds and host pages, run through `wp eval-file` |
| `fixtures/form-processors/` | The test Form Processor definitions |
| `fixtures/mu-plugins/gfcv-test-helpers.php` | Test-only must-use plugin: loopback, synchronous feeds, mail capture, capture endpoint, nonce shortcode |
| `fixtures/test-users.json` | Credentials shared by the scripts and the specs |

## Requirements

- **Agileware's ESR CiviCRM build.** It bundles the Form Processor, Action Provider and
  `uk.co.mjwconsult.checksum` extensions the suite enables. `setup-environment.sh` stops with
  an error if one is missing.
- **Gravity Forms and the Gravity Forms Webhooks add-on**, which are commercially licensed. In
  CI they are installed by `install-dependencies.sh` with the `GRAVITYFORMS_LICENSE_KEY`
  secret. Pull requests from forks do not receive secrets, so the suite only runs on branches
  in the agileware organisation. Locally, if both plugins are already installed they are simply
  activated.
- **Apache**, so the export directory's `.htaccess` is honoured (J-03).

## Running locally

Any WordPress + CiviCRM site with Gravity Forms, the Webhooks add-on and this plugin (mounted
at `wp-content/plugins/gf-civicrm`) will do. Under ddev, with `cv` on the container's default
PATH:

```bash
cd tests/playwright
npm ci
npx playwright install --with-deps

export BASE_URL=https://your-site.ddev.site
export CIVI_EXEC_PREFIX="docker exec -u $(id -u) -w /var/www/html/wp-content/plugins/gf-civicrm ddev-your-site-web"
export WP_ADMIN_USER=admin WP_ADMIN_PASS=admin

# one-off: dependencies, then roles, users, Form Processors, seed data, forms and pages
$CIVI_EXEC_PREFIX bash tests/playwright/fixtures/install-dependencies.sh
$CIVI_EXEC_PREFIX wp plugin activate gf-civicrm
$CIVI_EXEC_PREFIX bash tests/playwright/fixtures/setup-environment.sh

npm test
```

`CIVI_EXEC_PREFIX` is prepended to every `wp`/`cv` call the specs make. Use `docker exec` rather
than `ddev exec`: `ddev exec` re-parses its arguments through a shell, which expands the `$`
signs in the PHP the specs pass to `wp eval`. CI sets it to the equivalent `docker exec` command.

Three differences from CI worth knowing if you build your own site:

- **ddev defaults to nginx**, which ignores `.htaccess`: set `webserver_type: apache-fpm`, and
  write WordPress's standard `.htaccess` rules (wp-cli cannot detect `mod_rewrite` from the
  command line, so `wp rewrite flush --hard` does not write them).
- **ddev allows 8 PHP-FPM workers.** The remote-connection suite points a CMRF profile back at
  the same site, so each connection check holds a worker while its nested REST call needs
  another, and the settings page runs eleven checks at once. Raise `pm.max_children` (40 is
  plenty) or the site deadlocks.
- **Gravity Forms' setup wizard** is marked done by `setup-environment.sh`; on a fresh install it
  otherwise covers every Gravity Forms admin page and blocks clicks.

## Things that will bite you

**Choice values.** Gravity Forms renders a choice whose value is blank with its *label* as the
value (`GF_Field::get_choice_option_value()`), so "Add new card" is submitted - and stored - as
`Add new card`. D-09 exists because the plugin's offered-choices check once compared against the
raw values and rejected it.

**Administrators and Gravity Forms capabilities.** Without a role manager plugin, Gravity Forms
gives administrators only the virtual `gform_full_access` capability, so
`current_user_can( 'gravityforms_edit_settings' )` is false for an administrator. Plugin code
must use `GFCommon::current_user_can_any()`. I-02 and H-04/H-07 fail if it does not.

**Loopback.** In CI the site URL is http://localhost:8080, but inside the WordPress container
Apache listens on port 80. The must-use plugin reissues WordPress HTTP requests for
`localhost:8080` to port 80, so webhooks to `{rest_api_url}` work. CMRF uses PHP's curl directly,
so its profile is pointed at `http://localhost/` explicitly.

**Synchronous feeds.** The must-use plugin turns off asynchronous feed processing, so the entry
meta is complete when the confirmation page loads. Asynchronous retries are verified manually.

## Conventions

- **Assert on data, not just the page.** Check the entry, the entry meta, what the capture
  endpoint received, or CiviCRM itself - `fixtures/gf.ts`, `capture.ts` and `civi.ts`.
- **Restore what you mutate.** Settings changes go through `withPluginSettings()`; temporary
  feeds and options are removed in a `finally`. Spec ordering must never matter.
- **Name seeded records `GFCVTEST…` / `Gfcvtest …`.** Assertions never depend on incidental data.
- **Known issues are expected failures.** A pre-existing bug the suite finds is asserted as the
  intended behaviour and marked `test.fail(true, 'Known issue: …')`, with the cause in a
  comment. The test flags as soon as the bug is fixed; remove the `test.fail` then.
- **The remote-connection suite runs last.** Activating CMRF changes plugin behaviour globally,
  so `remote-cmrf.spec.ts` is its own Playwright project that depends on the others.

## Adding tests

A new field type, setting or admin action should arrive with its own spec, a host form and page
in `seed-forms.php`, and any records it needs in `seed-data.php`. Keep environment concerns in the
central workflow and plugin-specific setup here.
