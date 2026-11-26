# Troubleshooting

To troubleshoot this integration, enable the Gravity Forms Logging on the page `/wp-admin/admin.php?page=gf_settings&subview=settings` and then check the Web Hooks logs when the Gravity Form is submitted. Logs are available on this page, `/wp-admin/admin.php?page=gf_settings&subview=gravityformslogging`
This should help you identify the cause of most issues integrating the Gravity Form and CiviCRM.

CiviCRM expects permalink settings to be set to "Post name" by default in order to address the `wp-json` directory. Enable this setting on the page `/wp-admin/options-permalink.php` if it is not set already.

If you are a Web Developer and know how to set up and use [PHP Xdebug](https://xdebug.org/), it is useful to debug the Form Processor called by the Web Hook by appending &XDEBUG_SESSION=1 to the Web Hook URL in the Gravity Forms, Webhooks Feeds. This will trigger the Xdebug session to start.

## Logging Webhook Results

Webhook responses will be saved onto each entry's meta. You can view the response in the UI under Webhook Result when viewing a single entry. This can be useful as part of debugging failed webhook requests.

## Webhook Alerts

Sometimes webhook requests fail. They may also appear to succeed, but return an error from the Form Processor. You can choose to receive email alert notifications when this happens by enabling Webhook Alerts on this page `/wp-admin/admin.php?page=gf_settings&subview=gf-civicrm`. Provide an email address to direct alerts.

The alert email will include the error message, the Feed, and the Entry ID.

## CiviCRM Source

The **CiviCRM Source** setting is available on Drop Down, Multi Select, Checkboxes and Radio Buttons fields. The Multiple Choice field was introduced into Gravity Forms later, and is currently not supported by this plugin: it does not offer the CiviCRM Source setting. See [Options from CiviCRM](civicrm-options.md).
