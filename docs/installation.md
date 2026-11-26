# Installation

## Requirements

* [CiviCRM](https://civicrm.org/download), either installed on the same WordPress site as this plugin, or a remote CiviCRM installation connected with the [Connector to CiviCRM with CiviMcRestFace plugin (CMRF)](https://github.com/CiviMRF/civimcrestface-wordpress). See [Remote CiviCRM](remote-civicrm.md).
* Integration with the CiviCRM Form Processor uses Web Hooks, which requires the [Gravity Forms Webhooks Add-on](https://www.gravityforms.com/add-ons/webhooks/). This add-on is currently bundled with the [Gravity Forms Elite License](https://www.gravityforms.com/elite-license-plan/)

## Install the plugin

1. In WordPress, install and enable this plugin.
2. On the CiviCRM Extensions page, install the following Extensions:
    1. [Action Provider](https://lab.civicrm.org/extensions/action-provider)
    2. [Form Processor](https://lab.civicrm.org/extensions/form-processor)
    3. [API Key Management](https://lab.civicrm.org/extensions/apikey)

To try the plugin with a working example, continue with [Example: a newsletter subscription form](newsletter-example.md). To use a CiviCRM installation on another server, see [Remote CiviCRM](remote-civicrm.md).
