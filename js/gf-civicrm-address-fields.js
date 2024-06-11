(function (doc, $) {
    if ( typeof gform !== 'undefined' ) {
        // Default the "Show values" option to true
        gform.addAction( 'gform_post_set_field_property', function ( name, field, value, previousValue ) {
            if ( name === 'civicrmOptionGroup' ) {
                var field_choice_values_enabled = jQuery('#field_choice_values_enabled');
                field_choice_values_enabled.prop( "checked", true );
                field_choice_values_enabled["enableChoiceValue"] = true;
                ToggleChoiceValue();
                SetFieldChoices();
            }
        } );
    }

    console.log("Hello");
    console.log( gf_civicrm_address_fields.states );

    function getByID(id) {
        return doc.getElementById(id);
    }

    // polyfill for Element.closest()
    if (!Element.prototype.closest) {
        Element.prototype.closest = function (selector) {
        let element = this;
        do {
            if (element.matches(selector)) {
                return element;
            }
            element = element.parentElement;
        } while (element);
            return null;
        };
    }

    /**
     * Replace element with content in HTML template
     */
    function replaceElement(element, content) {
        element.insertAdjacentHTML("afterend", content);
        element.parentElement.removeChild(element);
    }
    
    let statesListTemplate;
    let stateInputTemplate;

    /**
     * Watch for changes to country selectors on Address fields
     */
    $(doc.body).on("change", ".gf-civicrm-address-field .address_country select", function () {
        populateStateProvinceField(this, true);
    });

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
     * decorate any supported Address fields on a form
     * @param {HTMLFormElement} form
     */
    function decorateForm(form) {
        const fields = form.querySelectorAll(".gf-civicrm-address-field");
        if (fields.length > 0) {
            initialiseGFCiviCRMAddressField();
            fields.forEach(function (field) {
                // NB: must allow for hidden country input, e.g address type !== international
                const country_id = field.id.replace(/field_([0-9]+)_([0-9]+)/, "input_$1_$2_6");
                populateStateProvinceField(getByID(country_id), false);
            });
        }
    }

    function initialiseGFCiviCRMAddressField() {
        // Only proceed if we have not already initialised
        if (!statesListTemplate) {
            // Get the templates
            statesListTemplate = wp.template("gf-civicrm-state-list");
            stateInputTemplate = wp.template("gf-civicrm-state-any");
        }
    }

    /**
     * Replace the States subfield with a dropdown of states for the given Country.
     * 
     * Values taken from CiviCRM.
     */
    function useStatesList(input, states, clear_value) {
        // only keeping the current state value on initialisation of the form
        if (clear_value) {
            input.value = "";
        }
        const data = getAddressData(input, states);
        replaceElement(input, statesListTemplate(data));
    }

    /**
     * Replace States subfield with text field.
     */
    function useStateInput(input, clear_value) {
        // only replace if not already a string input
        if (input.tagName !== "INPUT") {
            // only keeping the current state value on initialisation of the form
            if (clear_value) {
                input.value = "";
            }
            replaceElement(input, stateInputTemplate(getAddressData(input)));
        }
    }

    /**
     * Switch between dropdown and simple input depending on which Country was selected.
     */
    function populateStateProvinceField(country_select, clear_value) {
        const address_field = country_select.closest(".ginput_container_address");
        const state_input = address_field.querySelector(".address_state select,.address_state input");

        // Exit if there's no State field
        if (!state_input) {
            return;
        }
        
        const country = country_select.value;
        const field = gf_civicrm_address_fields.fields.inputs[state_input.id];

        // Record initial state of autocomplete and selected Aria attributes
        if (field) {
            field.autocomplete = state_input.getAttribute("autocomplete");
            field.required = state_input.getAttribute("aria-required");
            field.describedby = state_input.getAttribute("aria-describedby");
        }

        if (gf_civicrm_address_fields.states[country]) {
            useStatesList(state_input, gf_civicrm_address_fields.states[country], clear_value);
        } else {
            useStateInput(state_input, clear_value);
        }
    }

    /**
     * Create data for State field template use.
     */
    function getAddressData(input, states) {
        const data = {
            field_name: input.name,
            field_id: input.id,
            tabindex: input.tabIndex,
            state: input.value
        };

        const field = gf_civicrm_address_fields.fields.inputs[data.field_id];

        if (field) {
            data.autocomplete = field.autocomplete;
            data.required = field.required;
            data.describedby = field.describedby;
        }

        if (data.field_id in gf_civicrm_address_fields.fields.inputs) {
            data.placeholder = gf_civicrm_address_fields.fields.inputs[data.field_id].placeholder;
        }

        if (states !== undefined) {
            data.states = states;
        }
        return data;
    }
})(document, jQuery);