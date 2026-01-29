<?php

namespace GFCiviCRM;

use CRM_Core_Exception;

/**
 * Replace choices in Gravity Forms with CiviCRM data
 *
 * @param array $form
 * @param string $context
 *
 * @return array
 * @throws \API_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function do_civicrm_replacement( $form, $context ) {
	static $civi_fp_fields;
	static $option_group_ids;

	// Check if a CiviCRM installation exists
	if ( check_civicrm_installation()['is_error'] ) {
		return $form;
	}

	foreach ( $form['fields'] as &$field ) {
		if ( property_exists( $field, 'choices' ) && property_exists( $field, 'civicrmOptionGroup' ) &&
			 preg_match( '{(?:^|\s) civicrm (?: __ (?<option_group>\S+) | _fp__ (?<processor>\S*?) __ (?<field_name> \S*))}x', $field->civicrmOptionGroup, $matches ) ) {

			// Get the CiviCRM REST Connection Profile. This may be the local CiviCRM connection if no profile is set.
			$profile_name = get_rest_connection_profile( $form );

			[ 'option_group' => $option_group, 'processor' => $processor, 'field_name' => $field_name ] = $matches;

			$default_option = NULL;

            $field->inputs = NULL;

			if ( $option_group ) {
				if ( empty( $option_group_ids[$option_group] ) ) {
					// Get the option group id from the name, since it's more reliable
					$api_params = [
						'sequential' => 1,
  						'return' => ["id", "name"],
						'name'		=> $option_group,
						'is_active' => 1,
						'api.OptionValue.get' => [
							'return' => ["id", "label", "value", "is_default"], 
							'is_active' => 1, 
							'sort' => "weight ASC"
						],
					];
					$option_group_data = api_wrapper( $profile_name, 'OptionGroup', 'getsingle', $api_params, ['limit' => 1] );

					if ( !$option_group_data || !$option_group_data['api.OptionValue.get']['values'] ) {
						// TODO log an error
						continue; // No group found for the given name
					}

					if ( !$option_group_data['api.OptionValue.get']['values'] ) {
						// TODO log an error
						continue; // No options found for the given group
					} else {
						$options = $option_group_data['api.OptionValue.get']['values'] ?? [];
						$option_group_data['options'] = $options;
						unset($option_group_data['api.OptionValue.get']);
					}

					$option_group_ids[$option_group] = $option_group_data;
				}

                $field->choices = [];

				// Build the options
                foreach ( $option_group_ids[$option_group]['options'] as [ 'value' => $value, 'label' => $label, 'is_default' => $is_default ] ) {
					$field->choices[] = [
						'text'       => $label,
						'value'      => $value,
						'isSelected' => (bool) ( $is_default ?? FALSE )
					];
                }
			} elseif ( $processor && $field_name ) {
				try {
					if ( ! isset( $civi_fp_fields[$processor] ) ) {
						$api_params = [ 'api_action' => $processor ];
						$api_options = [ 'limit' => 0, ];						
						$civi_fp_fields[ $processor ] = api_wrapper( $profile_name, 'FormProcessor', 'getfields', $api_params, $api_options ) ?? [];
					}

					if ( isset( $field->defaultValue ) && ! empty( $field->defaultValue ) ) {
						// If the field has a default value set then that has priority
						$default_option = $field->defaultValue;
					} else {
						// Otherwise, retrieve the default value from the form processor
						$default_option = fp_tag_default( [
							$field->civicrmOptionGroup,
							$processor,
							$field_name,
						], NULL, TRUE );
					}

					$field->choices = [];

					// Try to find the field once by name in the key
					$fp_field = $civi_fp_fields[$processor][$field_name] ?? null;

					// If not found by key, search by 'name' field
					if ( ! $fp_field ) {
						foreach ( $civi_fp_fields[$processor] as $candidate ) {
							if ( isset( $candidate['name'] ) && $candidate['name'] === $field_name ) {
								$fp_field = $candidate;
								break;
							}
						}
					}

					// Build the options list
					if ( $fp_field && ! empty( $fp_field['options'] ) ) {
						foreach ( $fp_field['options'] as $value => $label ) {
							$field->choices[] = [
								'text'       => $label,
								'value'      => $value,
								'isSelected' => ( ( is_array( $default_option ) && in_array( $value, $default_option ) ) || ( $value == $default_option ) ),
							];
						}

						// Force the 'Show Values' option to be set, required for the label/value pairs to be saved
						$field['enableChoiceValue'] = true;
					}
					
				} catch ( CRM_Core_Exception $e ) {
					// Couldn't get form processor instance, don't try to set options
                    return $form;
				}
			}

			// Add checkboxes to the form entry meta
			if ( $field->type == 'checkbox' ) {
                $i = 0;
                $field->inputs = [];
				foreach ( $field->choices as [ 'text' => $label ] ) {
					$field->inputs[] = [
						// Avoid multiples of 10, allegedly these are problematic
						'id'    => $field->id . '.' . ( ++ $i % 10 ? $i : ++ $i ),
						'label' => $label,
					];
				}
			}

			// Adds default none option
			if ( ( $context === 'pre_render' ) && ( ! $field->isRequired ) && ( $field->type != 'multiselect' ) && ( $field->type != 'checkbox' ) ) {
				array_unshift( $field->choices, [
					'text'       => __( '- None -', 'gf-civicrm' ),
					'value'      => NULL,
					'isSelected' => ! $default_option,
				] );
			}
		}
	}

	return $form;
}

function pre_render( $form, $ajax, $field_values, $context ) {
	if($context == 'form_config') {
        // Do not perform our pre-render callbacks when retrieving form configuration
        return $form;
    }

    // @TODO - Refactor this to a single loop.
	// @TODO - do_civicrm_replacement should be done first or last?

	// Only do this on form_display
	if ( $context !== 'form_display') {
		return $form;
	}

	// Use the default value if set for radio buttons 
	foreach ( $form['fields'] as &$field ) {
		if ( $field->inputType != 'radio' ) {
			continue;
		}

		if ( isset( $field->defaultValue ) && ! empty( $field->defaultValue ) ) {
			$default_value = $field->defaultValue;

			foreach ( $field->choices as &$choice ) {
				if ( $choice['text'] == $default_value ) {
					$choice['isSelected'] = TRUE;
				}
			}
		}
	}

	// Apply comma separated default values to multiselect and checkbox fields
	foreach ( $form['fields'] as &$field ) {
		// Check if the field is of a type that should have comma-separated defaults
		if ( in_array( $field->type, [ 'multiselect', 'checkbox' ] ) ) {
			// Check if the custom comma-separated default setting is set

			/* @TODO
			 * Test 1 - if this works with CiviCRM multi-value options
			 * Test 2 - what happens when a merge tag returns a value which has commas in it, does it call this function again?
			 */

			if ( isset( $field->defaultValue ) && ! empty( $field->defaultValue ) ) {
				$defaults = explode( ',', trim( $field->defaultValue ) );
				// Apply these defaults to the field
				foreach ( $field->choices as $i => $choice ) {
					if ( in_array( trim( $choice['value'] ), $defaults ) ) {
						$field->choices[ $i ]['isSelected'] = TRUE;
					}
				}
			}
		}
	}

	return do_civicrm_replacement( $form, 'pre_render' );
}

add_filter( 'gform_pre_render', 'GFCiviCRM\pre_render', 10, 4 );
add_filter( 'gform_pre_render', 'GFCiviCRM\pre_render', 10, 4 );
add_filter( '_disabled_gform_pre_process', function ( $form ) {
	remove_filter( 'gform_pre_render', 'GFCiviCRM\pre_render' );

	return $form;
} );

add_filter( 'gform_pre_validation', function ( $form ) {
	return do_civicrm_replacement( $form, 'pre_validation' );
} );
add_filter( 'gform_pre_submission_filter', function ( $form ) {
	return do_civicrm_replacement( $form, 'pre_submission_filter' );
} );
add_filter( 'gform_admin_pre_render', function ( $form ) {
	return do_civicrm_replacement( $form, 'admin_pre_render' );
} );

/**
 * Embed javascript to save the selected CiviCRM Source option.
 */
function editor_script() {
	?>
  	<script src="<?= plugin_dir_url( __FILE__ ) . 'js/gf-civicrm-merge-tags.js?ver=' . GF_CIVICRM_FIELDS_ADDON_VERSION; ?>"></script>
	<script src="<?= plugin_dir_url( __FILE__ ) . 'js/gf-civicrm-fields.js?ver=' . GF_CIVICRM_FIELDS_ADDON_VERSION; ?>"></script>

	<script type="text/javascript">
		for (let field of ['select', 'multiselect', 'checkbox', 'radio']) {
			fieldSettings[field] += ', .civicrm_optiongroup_setting'
		}

		jQuery(document).bind('gform_load_field_settings', function (event, field) {
			jQuery('#civicrm_optiongroup_selector').val(field.civicrmOptionGroup)
		})

		function SetCiviCRMOptionGroup({value}) {
			SetFieldProperty('civicrmOptionGroup', value);
		}

		jQuery(document).ready(function($) {
			// Enable display of the default value field for these other field types
			$(document).on('gform_load_field_settings', function(event, field, form) {
				if (field.inputType === 'radio' || field.inputType === 'multiselect' || field.inputType === 'checkbox') {
					$('.default_value_setting').show();
					$('#field_default_value').val(field.defaultValue);
				}
			});

			// Add default value, comma separated values help text
			$(document).bind('gform_load_field_settings', function(event, field, form) {
				// Check for specific field types or all types
				if (field.type === 'multiselect' || field.type === 'checkbox') {
					// Locate the default value setting field
					var defaultValueSetting = $('.field_setting:contains("Default Value")');

					// Check if the help text is already added
					if (!defaultValueSetting.find('.custom-help-text').length) {
						// Append the help text
						defaultValueSetting.append('<p class="custom-help-text description">Enter comma-separated values for default selections.</p>');
					}
				}
			});

			// Hide all choice labels for checkbox fields, feature is replaced by the default value field
			function updateChoiceLabelDisplay() {                
				$('.field-choice-label').css('display', 'none');
			}

			// Run when the form editor is initially loaded
			updateChoiceLabelDisplay();

			// Bind to the event that triggers when field settings are loaded/changed
			$(document).bind('gform_load_field_settings', function() {
				updateChoiceLabelDisplay();
			});

			function handleEditChoicesVisibility(selector) {
				// Get the selected option value
				var selectedValue = $(selector).val();
				
				// Show/Hide the Edit Choices field depending on the CiviCRM Source field value
				if (selectedValue === '') {
					$('.choices-ui__trigger-section').show();
				} else {
					$('.choices-ui__trigger-section').hide();
				}
			}

			// Target the select element
			$('#civicrm_optiongroup_selector').on('change', function() {
				handleEditChoicesVisibility('#civicrm_optiongroup_selector')
			});

			// MutationObserver to detect visibility changes
			var observer = new MutationObserver(function(mutationsList) {
				mutationsList.forEach(function(mutation) {
					// Check if the CiviCRM Sources field is now visible and handle the change
					if ($('#civicrm_optiongroup_selector').is(':visible')) {
						handleEditChoicesVisibility('#civicrm_optiongroup_selector')
					}
				});
			});

			// Start observing the select element for visibility changes
			var targetNode = document.getElementById('general_tab');
			// Configuration of the observer
			var config = {
				attributes: true,      // Monitor changes to attributes
				childList: true,       // Monitor changes to child nodes
				subtree: true,         // Monitor changes in the entire subtree
				characterData: true    // Monitor changes to text nodes
			};
			observer.observe(targetNode, config);
    });
	</script>
	<?php
}

add_action( 'gform_editor_js', 'GFCiviCRM\editor_script', 11, 0 );