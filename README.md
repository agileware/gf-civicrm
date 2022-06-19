# Gravity Forms CiviCRM Integration

This is a [WordPress](https://wordpress.org) plugin that integrates the [Gravity Forms plugin](https://www.gravityforms.com/) with [CiviCRM](https://civicrm.org) using the [Form Processor extension](https://civicrm.org/extensions/form-processor).

Provides the following features:
* Extends Gravity Forms to get option lists and defaults from linked CiviCRM Form Processors
* Will detect if the submitting user is logged in and if so, use their CiviCRM API Key for the form submission

# Requirements

Integration with the CiviCRM Form Processor uses Web Hooks, which requires the [Gravity Forms, Webhooks Add-on](https://www.gravityforms.com/add-ons/webhooks/). This add-on is currently bundled with the [Gravity Forms, Elite License](https://www.gravityforms.com/elite-license-plan/)

# Setting up a Newsletter Subscription form using Gravity Forms and CiviCRM

Use the following steps to set up a _Newsletter Subscription_ form using the example configuration provided.

1. In WordPress, install and enable this plugin.
2. On the CiviCRM Extensions page, install the following Extensions:
   1. [Action Provider](https://lab.civicrm.org/extensions/action-provider)
   2. [Form Processor](https://lab.civicrm.org/jaapjansma/form-processor)
   3. [API Key Management](https://lab.civicrm.org/extensions/apikey)
3. In CiviCRM, locate the CiviCRM "System User" Contact. This is the user account used to execute CiviCRM cron and scheduled jobs. If you do not have such a user, then best to create one now as this will be used by default for processing the form submissions. This user must have a corresponding WordPress user account. 
4. Open this CiviCRM Contact and click on the **API Key** tab.
5. Generate a **User API Key** and copy the **Site API Key**. 
6. In WordPress, open Gravity Forms and import the example Gravity Form, [gravityforms-newsletter_subscribe.json](example/gravityforms-newsletter_subscribe.json)
7. Open the imported Gravity Form, the following form should be displayed. ![Gravity Form, Newsletter Subscribe](images/gravityforms-example.png)
8. Click on **Webhooks**
9. Add a new **Webhook**
10. Configure the **Webhook**
11. Configure the Webhook as shown below. ![Gravity Form, Webhook](images/gravityforms-webhook.png)
12. In the **Request URL** parameters for the Webhook, replace the following values:
    1. Insert the website address, **replacing** bananas.org.au (_seriously, why did you enter that?_)
    3. **key**, enter the **Site API Key**
    4. **api_key**, enter the **User API Key**
    5. Example URL: `https://bananas.org.au/wp-json/civicrm/v3/rest?entity=FormProcessor&action=newsletter_subscribe&key=SITEKEY&api_key=APIKEY&json=1`
13. Save the Webhook
14. In CiviCRM, go to the Administer > Automation > Form Processors page, `/wp-admin/admin.php?page=CiviCRM&q=civicrm%2Fadmin%2Fautomation%2Fformprocessor%2F#/formprocessors`
15. Import example Form Processor, [civicrm-form-processor-newsletter_subscribe.json](example/civicrm-form-processor-newsletter_subscribe.json)
16. **Edit** the imported Form Processor. Verify that it has the required fields:
    1. first_name
    2. last_name
    3. email
17. In the Form Processor, edit the **Do Subscribe** action and select the CiviCRM Group that the Contact should be subscribed too for the **Configuration, Subscribe to mailing list**. This option must be set for the **Do Subscribe** action to succeed.
18. Note that the Form Processor has the name, **newsletter_subscribe** which used in the Webhook, Request URL. This is how the Webhook is **connected** to this Form Processor.
19. **Save** the Form Processor.
20. In WordPress, embed the Gravity Form in a new page.
21. Open a new Web Browser window, not logged into the website. Go to the new page and submit the Gravity Form.
22. In CiviCRM, confirm that the Contact was created and that the Contact was subscribed to the Group in CiviCRM.

_Note_: When a user who is not logged into the website submits the form, then the form submission will be processed using the CiviCRM "System User" as defined by the **User API Key**. However, if the user is logged into the website, then this plugin will change the form submission so that it is processed using the logged in user.

# Trouble-shooting

To trouble-shoot this integration, enable the Gravity Forms Logging on the page `/wp-admin/admin.php?page=gf_settings&subview=settings` and then check the Web Hooks logs when the Gravity Form is submitted. Logs are available on this page, `/wp-admin/admin.php?page=gf_settings&subview=gravityformslogging`
This should help you identify the cause of most issues integrating the Gravity Form and CiviCRM.

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

![Agileware](images/agileware-logo.png)
