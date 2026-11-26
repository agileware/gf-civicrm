# Using options defined in CiviCRM for choices in your form fields

For Gravity Forms fields that support setting choices (e.g. Drop Down, Checkboxes, Radio Buttons), you may use predefined option lists set in CiviCRM. These either can use Option Groups defined in CiviCRM directly or may be defined by your Form Processor (recommended):

1. Edit your Form Processor and add an input of one of the supported types:
    - Yes/No as Option List
    - Option Group
    - Custom Options
    - Mailing Group
    - Tags
2. Save your Form Processor
3. Edit your Gravity form and add one of these types of field:
    - Checkboxes or Multi Select (under Advanced Fields) for multiple option selection
    - Radio Buttons or Drop Down to allow selection of a single option
3. Under the General settings for your field, open the CiviCRM Source selection.
4. Locate your Form Processor in the option list headings, and under it, select the field you defined in step 1.
5. Press the "Edit Choices" button, and select "show values" - this will allow the CiviCRM options to be mapped directly.
   Note that the Choices will not appear filled from CiviCRM until you save the form and reload - also, if you change any options here your changes will be replaced with options filled from CiviCRM, so if you need to make any changes to the available options, including order, it is important to make them in the *Form Processor* configuration
6. Save the Form and either Preview or embed it to see the changes

**Default Value**: If you set defaults for the Form Processor input used as a "CiviCRM Source", these will be applied when the form is loaded, including any Retrieval criteria specified in the URL parameters. No need to set the Default Value attribute for the Gravity Form Field.
