# Remote CiviCRM

This plugin can support connections to a remote CiviCRM installation with the aid of the [Connector to CiviCRM with CiviMcRestFace plugin (CMRF)](https://github.com/CiviMRF/civimcrestface-wordpress). Refer to the installation notes there.

See the [DEVELOPERS documentation](../DEVELOPERS.md) for developer notes, including the recommended GF CiviCRM Companion Extension, which lets the remote API user work with fewer CiviCRM permissions.

## Configuring REST Connection Profiles

Once you have installed CMRF, you must configure a REST Connection profile and select it for use.

1. Go to Settings > CiviCRM McRestFace Connections.
1. Add a new connection profile to your desired CiviCRM installation and save it.
1. Go to Forms > Settings. Open the CiviCRM subview.
1. Under **CiviCRM REST Connection Profile**, choose a connection profile to use. This will be the default connection profile for all forms.
1. When you select a connection profile, a series of **preflight checks** will run to confirm a baseline connection to CiviCRM. This will help identify if a connection can be established and if the nominated API user who owns the API key provided has sufficient permissions for core CiviCRM API calls used by this plugin.
1. **Save** your changes.

## Default values on remote connections

On a remote connection, every API call runs as the CiviCRM user that owns the connection profile's API key, not as the person viewing the form. This affects form processors used for default values (`{civicrm_fp.$processor.$field}` merge tags):

* CiviCRM does not know who is logged in to WordPress, so "Contact: get contact ID of the currently logged in user" returns the API user, and the form would be pre-filled with the API user's details.
* The form processor's permission is checked against the API user, not the person viewing the form.

For a form processor used for default values on a remote connection, identify the contact with `cid` and `cs` retrieval criteria from the URL, validated with the "Contact: validate checksum" action (see [Processing form submissions as a specific Contact](contact-checksums.md)). Do not use actions that depend on the logged-in user.

## {gf_civicrm_rest_url} custom merge tag

You may use `{gf_civicrm_rest_url}` in your webhook **Request URL**. This will output either the full v3 REST URL from the selected connection profile, or the same contents as `{rest_api_url}` if not found.

Note that `{rest_api_url}` will not include `civicrm/v3/rest` at the end of the API URL, but `{gf_civicrm_rest_url}` will.
