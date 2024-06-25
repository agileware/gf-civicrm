/* Placeholder for JS */

if ( typeof gform !== 'undefined' ) {
    // Default the "Show values" option to true
    gform.addAction( 'gform_post_set_field_property', function ( name, field, value, previousValue ) {
        if ( name === 'civicrmOptionGroup' ) {
            enableFieldChoiceValues();
        }
    } );

    // Enable the "Show Values" option if a CiviCRM Source was selected
    jQuery(document).on('gform_load_field_settings', function( event, field, form ){
        if ( field['civicrmOptionGroup'] && field['civicrmOptionGroup'] != '' ) {
            enableFieldChoiceValues();
        }
    });

    function enableFieldChoiceValues() {
        var field_choice_values_enabled = jQuery('#field_choice_values_enabled');

        field_choice_values_enabled.prop( "checked", true );
        field_choice_values_enabled["enableChoiceValue"] = true;

        ToggleChoiceValue();
    }
}

/**
 * Form field input validation.
 */

(function (doc, $) {

    function getByID(id) {
        return doc.getElementById(id);
    }

    /**
     * Modify the form after initialisation
     */
    $(doc).on("gform_post_render", function (event, form_id) {
        const form = getByID("gform_" + form_id);
        if (form) {
            decorateForm(form);
        }
    });

    /**
     * decorate fields on the form
     * @param {HTMLFormElement} form
     */
    function decorateForm(form) {
        const form_fields = form.querySelectorAll(".gfield");
        if (form_fields.length > 0) {
            form_fields.forEach(function (field) {
                addMaxLengthToSingleLineTextFields(field);
                addMaxLengthToContactFields(field);
                addMaxLengthToEmailFields(field);
                addMaxLengthToPhoneFields(field);
            });
        }
    }

    /**
     * GFCV-89 Add maxlength to single-line text fields
     */
    function addMaxLengthToSingleLineTextFields(field) {
        if (!field.matches(".gfield--type-text, .gfield--input-type-text")) {
            return;
        }

        const field_input = field.querySelector("input");

        if (field_input.maxLength == null || field_input.maxLength == -1) { // GF sets this to -1 if no Max Characters set
            // No max length provided.
            field_input.maxLength = 255; // CiviCRM schema
        } else if (field_input.maxLength && field_input.maxLength > 255) {
            // Max length provided but exceeds CiviCRM limit
            console.log(field_input.maxLength);
            field_input.maxLength = 255; // CiviCRM schema
        } else {
            // Max length provided but does not exceed CiviCRM limit
            field_input.maxLength = field_input.maxLength;
        }
    }

    /**
     * GFCV-89 Add maxlength to Contact name fields.
     * 
     * NOTE: Forms may be built to use a Single-Line text field for Contact name.
     * This function checks for the built-in Name field type provided by Gravity Forms.
     */
    function addMaxLengthToContactFields(field) {
        if (!field.matches(".gfield--type-name, .gfield--input-type-name")) {
            return;
        }

        const first_name_input = field.querySelector(".name_first input[type=text]"); // GF Field input id
        const middle_name_input = field.querySelector(".name_middle input[type=text]");
        const last_name_input = field.querySelector(".name_last input[type=text]");

        if (first_name_input) {
            first_name_input.maxLength = 64; // CiviCRM schema
        }

        if (middle_name_input) {
            middle_name_input.maxLength = 64; // CiviCRM schema
        }

        if (last_name_input) {
            last_name_input.maxLength = 64; // CiviCRM schema
        }

    }

    /**
     * GFCV-89 Add maxlength to email fields
     * 
     * NOTE: Forms may be built to use a Single-Line text field for Email.
     * This function checks for the built-in Email field type provided by Gravity Forms.
     */
    function addMaxLengthToEmailFields(field) {
        if (!field.matches(".gfield--type-email, .gfield--input-type-email")) {
            return;
        }

        const email_input = field.querySelector("input");
        email_input.maxLength = 254; // CiviCRM schema
    }

    /**
     * GFCV-89 Add maxlength to single-line text fields
     */
    function addMaxLengthToPhoneFields(field) {
        if (!field.matches(".gfield--type-phone .gfield--input-type-phone")) {
            return;
        }

        const field_input = field.querySelector("input");
        field_input.maxLength = 32; // CiviCRM schema
    }

})(document, jQuery);