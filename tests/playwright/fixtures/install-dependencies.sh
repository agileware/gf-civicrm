#!/usr/bin/env bash
# Installs and activates the plugins gf-civicrm depends on, BEFORE gf-civicrm itself is
# activated. Run by the central workflow as PRE_ACTIVATE_SCRIPT.
#
# gf-civicrm declares "Requires plugins: gravityforms", and since WordPress 6.5 `wp plugin
# activate` refuses a plugin whose required plugins are not active - so Gravity Forms has to be
# in place first. Gravity Forms and its Webhooks add-on are commercially licensed and are not in
# the CI image: they are installed with the free Gravity Forms CLI plugin and the licence key in
# GRAVITYFORMS_LICENSE_KEY.
#
# Locally (e.g. under ddev), if both plugins are already on disk they are simply activated and
# no licence key is needed.
set -euo pipefail

EXEC_PREFIX=${CIVI_EXEC_PREFIX:-}

run() {
  $EXEC_PREFIX "$@"
}

plugin_present() {
  run wp plugin is-installed "$1" >/dev/null 2>&1
}

if plugin_present gravityforms && plugin_present gravityformswebhooks; then
  echo "Gravity Forms and the Webhooks add-on are already installed - activating them."
  run wp plugin activate gravityforms gravityformswebhooks
  exit 0
fi

if [ -z "${GRAVITYFORMS_LICENSE_KEY:-}" ]; then
  echo "::error::GRAVITYFORMS_LICENSE_KEY is not set, and Gravity Forms is not installed." >&2
  echo "The gf-civicrm suite needs Gravity Forms and the Webhooks add-on, which are commercially" >&2
  echo "licensed. Pull requests from forks do not receive repository secrets, so this suite can" >&2
  echo "only run on branches in the agileware organisation." >&2
  exit 1
fi

echo "Installing Gravity Forms CLI..."
run wp plugin install gravityformscli --activate

echo "Installing Gravity Forms..."
run wp gf install --key="$GRAVITYFORMS_LICENSE_KEY" --activate --force

echo "Installing the Gravity Forms Webhooks add-on..."
run wp gf install gravityformswebhooks --key="$GRAVITYFORMS_LICENSE_KEY" --activate --force

echo "Gravity Forms dependencies installed."
