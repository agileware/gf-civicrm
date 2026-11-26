# Gravity Forms CiviCRM Integration

This is a [WordPress](https://wordpress.org) plugin that integrates the [Gravity Forms plugin](https://www.gravityforms.com/) with [CiviCRM](https://civicrm.org) using the [Form Processor extension](https://civicrm.org/extensions/form-processor).

Develop forms that integrate with CiviCRM to add common features to your website like:

* Newsletter subscription form
* Donation form
* Contact details update form
* Credit card update details form
* Recurring donation update form
* Update Activity Details form

## Quick start

1. Install this plugin and the CiviCRM extensions it needs. See [Installation](docs/installation.md).
2. Build a working form from the included example. See [Example: a newsletter subscription form](docs/newsletter-example.md).
3. Fill fields and choices from CiviCRM. See [Default values from CiviCRM](docs/default-values.md) and [Options from CiviCRM](docs/civicrm-options.md).
4. For forms that act on behalf of an existing contact, use checksum links. See [Processing form submissions as a specific Contact](docs/contact-checksums.md).

## Requirements

* [CiviCRM](https://civicrm.org/download), either installed on the same WordPress site as this plugin, or a remote CiviCRM installation connected with the [Connector to CiviCRM with CiviMcRestFace plugin (CMRF)](https://github.com/CiviMRF/civimcrestface-wordpress). See [Remote CiviCRM](docs/remote-civicrm.md).
* Integration with the CiviCRM Form Processor uses Web Hooks, which requires the [Gravity Forms Webhooks Add-on](https://www.gravityforms.com/add-ons/webhooks/). This add-on is currently bundled with the [Gravity Forms Elite License](https://www.gravityforms.com/elite-license-plan/)

## Documentation

| Page | What it covers |
|---|---|
| [Installation](docs/installation.md) | Requirements, and installing the plugin and the CiviCRM extensions it uses |
| [Example: a newsletter subscription form](docs/newsletter-example.md) | Step by step: an example Gravity Form, its Webhook and the matching Form Processor |
| [Default values from CiviCRM](docs/default-values.md) | The Default Form Processor option, and merge tags that fill fields from a Form Processor |
| [Options from CiviCRM](docs/civicrm-options.md) | Filling Drop Down, Checkboxes and Radio Buttons choices from CiviCRM |
| [CiviCRM Group Contact Select field](docs/group-contact-select.md) | Listing the contacts in a CiviCRM Group or Saved Search, and how CiviCRM permissions and ACLs apply |
| [Processing form submissions as a specific Contact](docs/contact-checksums.md) | Checksum links and validation, and the deprecated `{civicrm_api_key}` merge tag |
| [Importing and exporting](docs/import-export.md) | Exporting and importing forms, feeds and Form Processors, and protecting the export directory |
| [Remote CiviCRM](docs/remote-civicrm.md) | Connecting to a CiviCRM installation on another server, default values on remote connections, and `{gf_civicrm_rest_url}` |
| [Troubleshooting](docs/troubleshooting.md) | Logging, webhook results and alerts, and known limitations |
| [Developer notes](DEVELOPERS.md) | Remote CiviCRM development notes and the API wrapper |
| [Security policy](SECURITY.md) | How to report a security vulnerability |

# License

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see https://www.gnu.org/licenses/.

# About the Authors

This WordPress plugin was developed by the team at
[Agileware](https://agileware.com.au).

[Agileware](https://agileware.com.au) provide a range of WordPress and CiviCRM development services
including:

* CiviCRM migration
* CiviCRM integration
* CiviCRM extension development
* CiviCRM support
* CiviCRM hosting
* CiviCRM remote training services

Support your Australian [CiviCRM](https://civicrm.org) developers, [contact
Agileware](https://agileware.com.au/contact) today!

![Agileware](docs/logo/agileware-logo.png)
