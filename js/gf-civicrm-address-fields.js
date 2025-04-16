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
            for(const field of address_fields ){
                addMaxLengthToAddressFields(field);

                // NB: must allow for hidden country input, e.g address type !== international
                const country_id = field.id.replace(/field_([0-9]+)_([0-9]+)/, "input_$1_$2_6");
                populateStateProvinceField(getByID(country_id), false);
            }
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

    const emptyOption = $('<option value=""></option>')[0];

    /**
     * Replace the States subfield with a dropdown of states for the given Country.
     * 
     * Values taken from CiviCRM.
     */
    function useStatesList(input, states) {	
        const state_field = input.closest(".ginput_address_state");

        const held = input.value;

        // Create options from the list of state and use only those for the select
        const options = states.map(([key, name]) => $(`<option value="${name}">${name}</option>`)[0]);
        input.replaceChildren(emptyOption, ...options);

        // Reset the value _after_ mutations have run.
        queueMicrotask(function () {
            // Reapply the previous value if needed; allow stateFuturesElement to handle values that didn't exist yet.
            if (held && !input.value) {
                input.value = held;
            }

            // Deferred value should have been applied already, clear out any leftover.
            input.cleanFuture?.()
        });

        // Ensure visible and submittable
        input.disabled = false;	
        state_field.style.visibility = 'visible';
    }

    /**
     * Hide the States subfield if no states are found for the given Country.
     */
    function hideStateInput(input) {
        input.replaceChildren(emptyOption);
        input.disabled = true;

        const state_field = input.closest(".ginput_address_state");

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
     * GFCV-149 Converts an input element to a select element that can hold a deferred value.
     * When an option is added to the select element that matches the deferred value, set it then.
     *
     * @param state_input
     */
    function stateFuturesElement(state_input) {
        const state_select = document.createElement('select');

        // Replace the input
        state_input.replaceWith(state_select);
        state_select.id = state_input.id;
        delete state_input.id;

        // Copy attributes, exclude confusable value, placeholder
        for (const {name, value} of state_input.attributes) switch(name) {
            case 'value':
            case 'placeholder':
                continue;
            default:
                state_select.setAttribute(name, value);
        }

        // Define storage for value get / set
        const futureValue = Symbol();
        const valueStorage = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');

        // Define alternative property storage
        Object.defineProperty(state_select, 'value', {
            enumerable: true,
            set: function(newValue) {
                // use valid input, defer not-yet-valid input
                if(Array.from(this.options).some(({value}) => value === newValue)) {
                    this[futureValue] = null;
                    valueStorage.set.call(this, newValue);
                } else {
                    this[futureValue] = newValue;
                }
            },
            /*get: function() {
                // Only ever return a fully set value.
                return valueStorage.get.call(this);
            }*/
        })

        // Add callback to clear out deferred values
        state_select.cleanFuture = function () {
            this[futureValue] = null;
        }

        // Observe any incoming option elements in case the deferred value becomes valid
        const observer = new MutationObserver(function(mutations) {
            for(const node of mutations.flatMap(({addedNodes}) => Array.from(addedNodes))) {
                if(node instanceof HTMLOptionElement && node.value === state_select[futureValue]) {
                    // Matched our stored value, so use this option
                    node.setAttribute('selected', 'selected');
                    valueStorage.set.call(state_select, node.value);
                    state_select[futureValue] = null;
                }
            }
        });

        observer.observe(state_select, { childList: true });

        // Try and set the select element to the same value as the input
        state_select.value = state_input.value;

        return state_select;
    }

    /**
     * Switch between dropdown and simple input depending on which Country was selected.
     */
    function populateStateProvinceField(country_select) {
        const address_field = country_select.closest(".ginput_container_address");
        let state_input = address_field.querySelector(".address_state select,.address_state input");

        // Exit if there's no State field
        if (!state_input) {
            return;
        }

        // Make state_input a select element with future option handling
        if(state_input instanceof HTMLInputElement) {
            state_input = stateFuturesElement(state_input);
        }

        const country = country_select.value;
        const field = gf_civicrm_address_fields.fields.inputs[state_input.id];

        // Record initial state of autocomplete and selected Aria attributes
        if (field) {
            field.autocomplete = state_input.getAttribute("autocomplete");
            field.required = state_input.getAttribute("aria-required");
            field.describedby = state_input.getAttribute("aria-describedby");
        }

        if (state_input.dataset.country === country) {
            return;
        }

        state_input.dataset.country = country;

        if (gf_civicrm_address_fields.states[country]) {
            useStatesList(state_input, gf_civicrm_address_fields.states[country]);
        } else {
            hideStateInput(state_input);
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
