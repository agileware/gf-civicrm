#!/usr/bin/env bash
# Creates the WordPress roles/users, CiviCRM extensions, Form Processors, seed data, Gravity
# Forms forms/feeds and host pages that the Playwright suite needs.
#
# Assumes WordPress + CiviCRM are installed, Gravity Forms and the Webhooks add-on are active
# (install-dependencies.sh) and this plugin is active. Runs with `wp`, `cv` and `php` on the
# PATH - true inside the CI WordPress container, and under `ddev exec` locally. Set
# CIVI_EXEC_PREFIX to wrap each call if running from outside that environment.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXEC_PREFIX=${CIVI_EXEC_PREFIX:-}

run() {
  $EXEC_PREFIX "$@"
}

json_get() {
  # $1 = json file, $2 = top-level key, $3 = nested key
  run php -r 'echo json_decode(file_get_contents($argv[1]), true)[$argv[2]][$argv[3]];' "$1" "$2" "$3"
}

USERS_JSON="$SCRIPT_DIR/test-users.json"

# ---------------------------------------------------------------- roles

echo "Creating WordPress roles..."
# CRM_Core_Permission_WordPress::check() lowercases the CiviCRM permission string and replaces
# each run of non-alphanumeric characters with a single underscore before calling
# current_user_can(), so CiviCRM permissions are granted here in that munged form -
# "access CiviCRM" becomes access_civicrm.
#
# gfcv_form_editor can build forms and read entries, but must not reach the settings actions,
# connection checks or import/export. gfcv_settings_manager can use the settings actions but
# is not a CiviCRM administrator, so must not import Form Processors into a local CiviCRM.
run wp role create gfcv_member "GFCV Member (test)" --clone=subscriber >/dev/null 2>&1 || true
run wp cap add gfcv_member access_civicrm

run wp role create gfcv_form_editor "GFCV Form Editor (test)" --clone=subscriber >/dev/null 2>&1 || true
run wp cap add gfcv_form_editor \
  gravityforms_edit_forms \
  gravityforms_create_form \
  gravityforms_view_entries \
  gravityforms_export_entries

run wp role create gfcv_settings_manager "GFCV Settings Manager (test)" --clone=subscriber >/dev/null 2>&1 || true
run wp cap add gfcv_settings_manager \
  gravityforms_edit_forms \
  gravityforms_view_entries \
  gravityforms_export_entries \
  gravityforms_view_settings \
  gravityforms_edit_settings \
  access_civicrm

run wp role create gfcv_api "GFCV API user (test)" --clone=subscriber >/dev/null 2>&1 || true
run wp cap add gfcv_api \
  access_civicrm \
  administer_civicrm \
  view_all_contacts \
  edit_all_contacts \
  add_contacts

# ---------------------------------------------------------------- users

create_or_update_user() {
  local key="$1"
  local username password email role
  username=$(json_get "$USERS_JSON" "$key" username)
  password=$(json_get "$USERS_JSON" "$key" password)
  email=$(json_get "$USERS_JSON" "$key" email)
  role=$(json_get "$USERS_JSON" "$key" role)

  if run wp user get "$username" >/dev/null 2>&1; then
    run wp user update "$username" --user_pass="$password" --role="$role" >/dev/null
  else
    run wp user create "$username" "$email" --role="$role" --user_pass="$password" >/dev/null
  fi
}

echo "Creating WordPress users..."
for key in member otherMember formEditor settingsManager api; do
  create_or_update_user "$key"
done

# ---------------------------------------------------------------- CiviCRM extensions

echo "Enabling the CiviCRM components and extensions the suite depends on..."
# Form Processor (with Action Provider) and the checksum extension ship with Agileware's ESR
# CiviCRM build, so they only need enabling. The checksum extension provides the
# ContactChecksum.validate API that the CiviCRM Payment Token field calls.
run cv ext:enable civi_contribute civi_member
for ext in action-provider form-processor uk.co.mjwconsult.checksum; do
  if ! run cv ext:list -L 2>/dev/null | grep -q "$ext"; then
    echo "::error::The CiviCRM extension $ext is not available. The suite expects Agileware's ESR CiviCRM build, which bundles it." >&2
    exit 1
  fi
done
run cv ext:enable action-provider form-processor checksum

echo "Importing the test Form Processors..."
for processor in gfcvtest_contact gfcvtest_restricted; do
  run cv api3 FormProcessorInstance.Import \
    file="$SCRIPT_DIR/form-processors/$processor.json" import_locally=1 >/dev/null
done

# ---------------------------------------------------------------- civicrm seed data

echo "Seeding CiviCRM test data..."
run cv scr "$SCRIPT_DIR/seed-data.php"

# ---------------------------------------------------------------- WordPress configuration

echo "Installing the test-only must-use plugin..."
MU_DIR=$(run wp eval 'echo WPMU_PLUGIN_DIR;')
run mkdir -p "$MU_DIR"
run cp "$SCRIPT_DIR/mu-plugins/gfcv-test-helpers.php" "$MU_DIR/gfcv-test-helpers.php"

echo "Marking the Gravity Forms setup wizard as done..."
# A fresh Gravity Forms install shows its setup wizard as a modal over every Gravity Forms
# admin page until it is completed, which blocks clicks on the settings and import/export pages.
run wp option update gform_pending_installation 0 >/dev/null

echo "Logging PHP errors to wp-content/debug.log without displaying them..."
run wp config set WP_DEBUG true --raw --type=constant
run wp config set WP_DEBUG_LOG true --raw --type=constant
run wp config set WP_DEBUG_DISPLAY false --raw --type=constant

echo "Installing the CMRF connector (activated only by the remote-connection suite)..."
if ! run wp plugin is-installed connector-civicrm-mcrestface >/dev/null 2>&1; then
  run wp plugin install connector-civicrm-mcrestface >/dev/null
fi
run wp plugin deactivate connector-civicrm-mcrestface >/dev/null 2>&1 || true

# ---------------------------------------------------------------- forms and pages

echo "Seeding Gravity Forms forms, feeds, pages and plugin settings..."
# cv can print stray whitespace around the value (e.g. a leading newline), and $(...) only
# strips trailing newlines, so trim it all: the key is sent verbatim in webhook URLs.
SITE_KEY=$(run cv ev 'echo CIVICRM_SITE_KEY;' | tr -d '[:space:]')
API_KEY=$(json_get "$USERS_JSON" api apiKey)
run wp eval-file "$SCRIPT_DIR/seed-forms.php" "$SITE_KEY" "$API_KEY"

echo "Flushing caches so the first test run starts cold..."
run wp transient delete --all >/dev/null 2>&1 || true
run wp option delete gfcvtest_mail gfcvtest_capture >/dev/null 2>&1 || true

echo "Test environment ready."
