# Default values from CiviCRM

## Using the Default Form Processor option

You can optionally set a default form processor for each form. 

If set, 

* `Default / $processor / $field` options will appear in the Merge Tags selector.
* Using the Merge Tag format `{civicrm_fp.default_fp.$field}` in the **Default Value** will pull the value from the default form processor.
* The CiviCRM Source option list will include a `DEFAULT Form Processor` option group. This will be populated by the inputs of the default form processor.

To set a default form processor.

1. Open your form's CiviCRM form settings page.
2. Insert your form processor's name in the *Default Form Processor* field.
3. Save your settings.

## Using Merge Tags for default field values

When setting up your field in Gravity Forms, you can use a Merge Tag of the form `{civicrm_fp.$processor.$field}` in the **Default Value** section of your Field's Advanced settings.

In the [newsletter subscription example](newsletter-example.md) for example, you could fill in the Email field with the current user's email address recorded in CiviCRM, you would use `{civicrm_fp.newsletter_subscribe.email}`, and set up the Retrieval of Defaults accordingly for the newsletter_subscribe Form Processor in CiviCRM.

The "Retrieval criteria for default data" specified in the form processor will be mapped to URL parameters when your Gravity Form is displayed to your users, such that if you create a criterion named `cid` to retrieve contact details by ID, you'd be able to specify contact ID _1234_ with a request like:

`https://bananas.org.au/newsletter_subscribe?cid=1234`
