# Processing form submissions as a specific Contact

Any Form Processor that should record actions as a specific Contact must implement checksum validation as part of the Form Processor.

1. Include fields in the Form Processor that are used for the checksum validation
    - `cid` for the Contact ID - this should be a **numeric input** (no decimal) in Form Processor
    - `cs` for the Checksum - this should be a **long text input** in Form Processor
2. These fields should also be included on the Gravity Form as hidden fields. Use the Merge Tags from the Form Processor as defaults and optionally, if you want to use a Contact checksum URL for the form (eg. ?cs=xxx&cid=123), enable for both these fields the option to set the value from the URL parameter (cs and cid).
3. Inside the Form processor "Retrieval of defaults" settings, use the "Contact: get contact ID of the currently logged in user" and "Contact: generate checksum" actions to provide default values to these fields
4. You can also use the "Retrieval criteria for default data" to add `cid` and `cs` criteria supporting checksum links generated from CiviCRM schedule reminders.
    - Works with any link that includes `?{contact.checksum}&cid={contact.id}` on the page with the Gravity Form.
    - Use the "Contact: validate checksum" action in Retrieval of Details to authenticate the link.
5. As part of the Form Processor actions, you must use the "Contact: validate checksum" to authenticate the Contact ID and checksum used to submit the form.
6. For Form Processors that *must* be processed on behalf of an existing contact, also use the "Contact: Validate checksum" action as part of the Form Processor Validation actions

An example of this setup is available,
- Import Gravity form: [gravityforms-update_card.json](../example/gravityforms-update_card.json)
- Import Form Processor: [civicrm-form-processor-update_card.json](../example/civicrm-form-processor-update_card.json)

## {civicrm_api_key} - CiviCRM API Key merge tag (Deprecated)

This method is **deprecated**, instead provide a CiviCRM API Key for a CiviCRM Contact with sufficient permissions to execute the Form Processor or use the method described in the above section, _Processing form submissions as a specific Contact_.

The `{civicrm_api_key}` Merge Tag will return the CiviCRM API Key for the user that submitted the form.
