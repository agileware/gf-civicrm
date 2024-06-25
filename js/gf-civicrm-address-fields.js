/**
 * Copyright (C) Agileware Pty Ltd
 * Based on original work by WebAware Pty Ltd (email : support@webaware.com.au)
 * 
 * This code is based on the original work by WebAware Pty Ltd.
 * The original plugin can be found at: https://gf-address-enhanced.webaware.net.au/
 * Original License: GPLv2 or later
 * Original License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

(function (doc, $) {

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
        const address_fields = form.querySelectorAll(".gf-civicrm-address-field");
        if (address_fields.length > 0) {
            initialiseGFCiviCRMAddressField();
            address_fields.forEach(function (field) {
                addMaxLengthToAddressFields(field);

                // NB: must allow for hidden country input, e.g address type !== international
                const country_id = field.id.replace(/field_([0-9]+)_([0-9]+)/, "input_$1_$2_6");
                populateStateProvinceField(getByID(country_id), false);
            });
        }
    }

    /**
     * GFCV-89 Add maxlength to single-line text fields
     */
    function addMaxLengthToSingleLineTextFields(field) {
        const address_field = field.querySelector(".ginput_container_address");
        const street_address_1_input = address_field.querySelector(".address_line_1 input");
        const street_address_2_input = address_field.querySelector(".address_line_2 input");
        const city_input = address_field.querySelector(".address_city input");
        const zip_input = address_field.querySelector(".address_zip input");

        if (street_address_1_input) {
            street_address_1_input.maxLength = 96;
        }

        if (street_address_2_input) {
            street_address_2_input.maxLength = 96;
        }

        if (city_input) {
            city_input.maxLength = 64;
        }

        if (zip_input) {
            zip_input.maxLength = 64;
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
        const state_field = input.closest(".ginput_address_state");
        const data = getAddressData(input, states);
        
        replaceElement(input, statesListTemplate(data));
        state_field.style.visibility = 'visible';
    }

    /**
     * Hide the States subfield if no states are found for the given Country.
     */
    function hideStateInput(input, clear_value) {
        if (clear_value) {
            input.value = null;
        }
        const state_field = input.closest(".ginput_address_state");

        replaceElement(input, stateInputTemplate(getAddressData(input)));
        state_field.style.visibility = 'hidden';
    }

    /**
     * GFCV-87 Add maxlength to Street Address fields
     */
    function addMaxLengthToAddressFields(field) {
        const address_field = field.querySelector(".ginput_container_address");
        const street_address_1_input = address_field.querySelector(".address_line_1 input");
        const street_address_2_input = address_field.querySelector(".address_line_2 input");
        const city_input = address_field.querySelector(".address_city input");
        const zip_input = address_field.querySelector(".address_zip input");

        if (street_address_1_input) {
            street_address_1_input.maxLength = 96;
        }

        if (street_address_2_input) {
            street_address_2_input.maxLength = 96;
        }

        if (city_input) {
            city_input.maxLength = 64;
        }

        if (zip_input) {
            zip_input.maxLength = 64;
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
            hideStateInput(state_input, clear_value);
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