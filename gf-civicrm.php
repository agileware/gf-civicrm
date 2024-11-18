<?php
/**
 * Plugin Name: Gravity Forms CiviCRM Integration
 * Plugin URI: https://github.com/agileware/gf-civicrm
 * Description: Extends Gravity Forms to get option lists and defaults from linked CiviCRM Form Processors in a local CiviCRM installation
 * Requires plugins: civicrm, gravityforms
 * Author: Agileware
 * Author URI: https://agileware.com.au
 * Version: 1.10.3-beta
 * Text Domain: gf-civicrm
 * 
 * Copyright (c) Agileware Pty Ltd (email : support@agileware.com.au)
 *
 * Gravity Forms CiviCRM Integration is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Gravity Forms CiviCRM Integration is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

namespace GFCiviCRM;

use Civi\Api4\{OptionValue, OptionGroup, Contact};
use CiviCRM_API3_Exception;

use GFCommon;
use GFAddon;
use function rgar;

use GFAPI;
use GFFormsModel;

const BEFORE_CHOICES_SETTING = 1350;

define( 'GF_CIVICRM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_CIVICRM_PLUGIN_SLUG', plugin_basename( __FILE__ ) );
define( 'GF_CIVICRM_PLUGIN_GITHUB_REPO', 'agileware/gf-civicrm' ); // GitHub username and repo

// Include the updater class
require_once GF_CIVICRM_PLUGIN_PATH . 'includes/class-gf-civicrm-upgrader.php';

// Initialize the updater
$updater = new Upgrader( __FILE__ );
$updater->init();

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
	foreach ( $form['fields'] as &$field ) {
		if ( property_exists( $field, 'choices' ) && property_exists( $field, 'civicrmOptionGroup' ) &&
		     preg_match( '{(?:^|\s) civicrm (?: __ (?<option_group>\S+) | _fp__ (?<processor>\S*?) __ (?<field_name> \S*))}x', $field->civicrmOptionGroup, $matches ) ) {
			if ( ! civicrm_initialize() ) {
				break;
			}

			[ 'option_group' => $option_group, 'processor' => $processor, 'field_name' => $field_name ] = $matches;

			$default_option = NULL;

            $field->inputs = NULL;

			if ( $option_group ) {
				$options = OptionValue::get( FALSE )
				                      ->addSelect( 'value', 'label', 'is_default' )
				                      ->addWhere( 'option_group_id:name', '=', $option_group )
				                      ->addWhere( 'is_active', '=', TRUE )
				                      ->addOrderBy( 'weight', 'ASC' )
				                      ->execute();

                $field->choices = [];

                foreach ( $options as [ 'value' => $value, 'label' => $label, 'is_default' => $is_default ] ) {
						$field->choices[] = [
							'text'       => $label,
							'value'      => $value,
							'isSelected' => (bool) ( $is_default ?? FALSE )
						];

                }
			} elseif ( $processor && $field_name ) {
				try {
					if ( ! isset( $civi_fp_fields[ $processor ] ) ) {
						$civi_fp_fields[ $processor ] = civicrm_api3( 'FormProcessor', 'getfields', [ 'action' => $processor ] )['values'] ?? [];
					}

					// If the field has a default value set then that has priority
					if ( isset( $field->defaultValue ) && ! empty( $field->defaultValue ) ) {
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

					foreach ( $civi_fp_fields[ $processor ][ $field_name ]['options'] ?? [] as $value => $label ) {
						$field->choices[] = [
							'text'       => $label,
							'value'      => $value,
							'isSelected' => ( ( is_array( $default_option ) && in_array( $value, $default_option ) ) || ( $value == $default_option ) ),
						];
						// Force the 'Show Values' option to be set, required for the label/value pairs to be saved
						$field['enableChoiceValue'] = true;
					}

				} catch ( CiviCRM_API3_Exception $e ) {
					// Couldn't get form processor instance, don't try to set options
                    return $form;
				}
			}

			if ( $field->type == 'checkbox' ) {
                $i = 0;
                $field->inputs = [];
				foreach ( $field->choices as [ 'label' => $label ] ) {
					$field->inputs[] = [
						// Avoid multiples of 10, allegedly these are problematic
						'id'    => $field->id . '.' . ( ++ $i % 10 ? $i : ++ $i ),
						'label' => $label,
					];
				}
			}

			if ( ( $context === 'pre_render' ) && ( ! $field->isRequired ) && ( $field->type != 'multiselect' ) && ( $field->type != 'checkbox' ) ) {
				array_unshift( $field->choices, [
					'text'       => __( '- None -', 'gf-civicrm-formprocessor' ),
					'value'      => NULL,
					'isSelected' => ! $default_option,
				] );
			}
		}

	}

	return $form;
}

function compose_merge_tags ( $merge_tags ) {
	try {
		foreach (
			civicrm_api3( 'FormProcessorInstance', 'get', [ 'sequential' => 1 ] )['values']
			as ['inputs' => $inputs, 'name' => $pname, 'title' => $ptitle]
		) {
			foreach ( $inputs as ['name' => $iname, 'title' => $ititle] ) {
				$merge_tags[] = [
					'label' => sprintf( __( '%s / %s', 'gf-civicrm' ), $ptitle, $ititle ),
					'tag'   => "{civicrm_fp.{$pname}.{$iname}}",
				];
			}
		}
	}
	catch(\CRM_Core_Exception $e) {
		// ...
	}

	return $merge_tags;
}

function pre_render( $form ) {
	// @TODO - Refactor this to a single loop.
	// @TODO - do_civicrm_replacement should be done first or last?

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

add_filter( 'gform_pre_render', 'GFCiviCRM\pre_render', 10, 1 );
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

add_filter( 'gform_username', function ( $username ) {
	return sanitize_user( $username );
} );

/**
 * Replaces comma separated values for multiselect with an actual array for JSON encoding
 *
 * @param \GF_Field $field
 * @param $entry
 * @param $input_id
 * @param $use_text
 * @param $is_csv
 *
 * @return array
 */
function fix_multi_values( \GF_Field $field, $entry, $input_id = '', $use_text = FALSE, $is_csv = FALSE ) {
	$selected = [];

	if ( empty( $input_id ) || absint( $input_id ) == $input_id ) {

		foreach ( $field->inputs as $input ) {

			$index = (string) $input['id'];

			if ( ! rgempty( $index, $entry ) ) {
				$selected[] = GFCommon::selection_display( rgar( $entry, $index ), $field, rgar( $entry, 'currency' ), $use_text );
			}

		}
	}

	return $selected;

}

/*
*
* Locale agnostic conversion of various currency formats to float.
* Possibly alternative implementation, but requires formatting locale as a parameter
* https://www.php.net/manual/en/numberformatter.parsecurrency.php
* 
*/

function convertInternationalCurrencyToFloat( $currencyValue ) {
	// Remove all non-numeric characters except commas and dots
	$number = preg_replace( '/[^\d.,]/', '', $currencyValue );

	// Detect the use of comma or dot as the last decimal separator
	if ( preg_match( '/\.\d{2}$/', $number ) ) {
		// Dot is the decimal separator and comma as thousand separator
		$number = str_replace( ',', '', $number );
	} elseif ( preg_match( '/,\d{2}$/', $number ) ) {
		// Comma is the decimal separator and dot as thousand separator
		$number = str_replace( '.', '', $number );
		$number = str_replace( ',', '.', $number );
	} else {
		// Assume no decimal places or unconventional usage, remove all commas and dots
		$number = str_replace( [ ',', '.' ], '', $number );
	}

	// Convert to float cast as a string because webhook will complain otherwise
	return (string) floatval( $number );
}

/*
* Extend the maximum attempts for webhook calls, so Gravity Forms does not give up and start bailing
*/

add_filter( 'gform_max_async_feed_attempts', 'GFCiviCRM\custom_max_async_feed_attempts' );

function custom_max_async_feed_attempts( $max_attempts ) {
	return 999999; // @TODO this could be a configurable option
}

/**
 * Replace request data output with json-decoded structures where applicable.
 *
 * @param $request_data
 * @param $feed
 * @param $entry
 * @param $form
 *
 * @return array
 */
function webhooks_request_data( $request_data, $feed, $entry, $form ) {
	// Form hooks don't seem to be called during webform request, so do it ourselves
	$form = do_civicrm_replacement( $form, 'webhook_request_data' );

	if ( $feed['meta']['requestFormat'] === 'json' ) {
		$rewrite_data = [];

		$multi_json = (bool) FieldsAddOn::get_instance()->get_plugin_setting( 'civicrm_multi_json' );

		/** @var \GF_Field $field */
		foreach ( $form['fields'] as $field ) {
			if ( property_exists( $field, 'storageType' ) && $field->storageType == 'json' ) {
				$rewrite_data[ $field['id'] ] = json_decode( $entry[ $field['id'] ] );
			} elseif (
				! empty( $multi_json ) &&  // JSON encoding selected in settings
				( is_a( $field, 'GF_Field_Checkbox' ) || is_a( $field, 'GF_Field_MultiSelect' ) ) // Multi-value field
			) {
				$rewrite_data[ $field->id ] = fix_multi_values( $field, $entry );
			}
            
			/*
			* Custom Price, Product fields send the value in $ 50.00 format which is problematic
			* @TODO If the $feed['meta']['fieldValues'][x] field has a value=gf_custom then custom_value will contain something like {membership_type:83:price} - this requires new logic extract the field ID. Will not contain the usual field ID.			
			*/

			if ( is_a( $field, 'GF_Field_Price' ) && $field->inputType == 'price' && isset( $entry[ $field->id ] ) ) {
				$rewrite_data[ $field->id ] = convertInternationalCurrencyToFloat( $entry[ $field->id ] );
			}
		}
		foreach ( $feed['meta']['fieldValues'] as $field_value ) {
			if ( ( ! empty( $field_value['custom_key'] ) ) && ( $value = $rewrite_data[ $field_value['value'] ] ?? NULL ) ) {
				$request_data[ $field_value['custom_key'] ] = $value;
			}
		}
	}

	return $request_data;
}

add_filter( 'gform_webhooks_request_data', 'GFCiviCRM\webhooks_request_data', 10, 4 );

/**
 * Add setting for CiviCRM Source to Gravity Forms editor standard settings
 *
 * @param int $position
 * @param int $form_id
 *
 * @throws \API_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function civicrm_optiongroup_setting( $position, $form_id ) {
	if ( ! civicrm_initialize() ) {
		return;
	}

	switch ( $position ) {
		case BEFORE_CHOICES_SETTING:
			$option_groups = OptionGroup::get( FALSE )
			                            ->addSelect( 'name', 'title' )
			                            ->addOrderBy( 'title', 'ASC' )
			                            ->execute();
			try {
				$form_processors = civicrm_api3( 'FormProcessorInstance', 'get', [ 'sequential' => 1 ] )['values'];

				$form_processors = array_filter( array_map( function ( $processor ) use ( $option_groups ) {
					$mapped = [
						'name'    => $processor['name'],
						'title'   => $processor['title'],
						'options' => [],
					];

					foreach ( $processor['inputs'] as $input ) {
						$type = &$input['type']['name'];

						if ( in_array( $type, [
							'OptionGroup',
							'CustomOptionListType',
							'YesNoOptionList',
							'MailingGroup',
							'Tag',
						] ) ) {
							$mapped['options'][ $input['name'] ] = $input['title'];
						}
					}

					return ! empty( $mapped['options'] ) ? $mapped : FALSE;
				}, $form_processors ) );
			} catch ( CiviCRM_API3_Exception $e ) {
				// Form processor extension may not be installed, ignore
				$form_processors = [];
			}
			?>
			<li class="civicrm_optiongroup_setting field_setting">
				<label for="civicrm_optiongroup_selector">
					<?php esc_html_e( 'CiviCRM Source', 'gf-civicrm-formprocessor' ); ?>
				</label>
				<select id="civicrm_optiongroup_selector"
				        onchange="SetCiviCRMOptionGroup(this)">
					<option value=""><?php esc_html_e( 'None' ); ?></option>
					<?php foreach ( $form_processors as $processor ): ?>
						<optgroup label="Form Processor: <?php echo $processor['title']; ?>">
							<?php foreach ( $processor['options'] as $pr_name => $pr_title ) {
								echo "<option value=\"civicrm_fp__{$processor['name']}__{$pr_name}\">{$pr_title}</option>";
							} ?>
						</optgroup>
					<?php endforeach; ?>
					<optgroup label="Option Groups">
						<?php foreach ( $option_groups as $group ) {
							echo "<option value=\"civicrm__{$group['name']}\">" . sprintf( __( '%1$s (ID: %2$u)', 'gf-civicrm' ), $group['title'], $group['id'] ) . "</option>";
						} ?>
					</optgroup>
				</select>
			</li>
			<?php
			break;
	}
}

add_action( 'gform_field_standard_settings', 'GFCiviCRM\civicrm_optiongroup_setting', 10, 2 );

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

/**
 * Replacement callback for GFCiviCRM\replace_merge_tags()
 *
 * @param array $matches
 *
 * @return string
 */
function fp_tag_default( $matches, $fallback = '', $multiple = FALSE ) {
	static $defaults = [];

	$result = $fallback;
	[ , $processor, $field ] = $matches;

	if ( ! civicrm_initialize() ) {
		return $result;
	}

	if ( ! isset( $defaults[ $processor ] ) ) {
		try {
			// Fetch Form Processor options directly from the GET parameters.
			$params = [ 'check_permissions' => 1 ];

			$fields = civicrm_api3( 'FormProcessorDefaults', 'getfields', [ 'action' => $processor ] );
			foreach ( array_keys( $fields['values'] ) as $key ) {
				if ( ! empty( $_GET[ $key ] ) ) {
					$params[ $key ] = $_GET[ $key ];
				}
			}

			$defaults[ $processor ] = civicrm_api3( 'FormProcessorDefaults', $processor, $params );
		} catch ( CiviCRM_API3_Exception $e ) {
			$defaults[ $processor ] = FALSE;
		}
	}

	if ( $defaults[ $processor ] && array_key_exists( $field, $defaults[ $processor ] ) ) {
		$result = $defaults[ $processor ][ $field ];
	}

	if ( $multiple ) {
		return $result;
	}

	// GFCV-20 Resolve to first value if array
	// @TODO - This may be interferring with the setting of multiple values using the default value field, form process merge tag
	while ( is_array( $result ) ) {
		$result = reset( $result );
	}

	return $result;
}

/**
 * Find or generate API key for the current user.
 */
function get_api_key() {
	if ( ! civicrm_initialize() ) {
		return NULL;
	}
	$contactID = \CRM_Core_Session::getLoggedInContactID();

	if ( (int) $contactID < 1 ) {
		return NULL;
	}

	// Get the existing API key if there is one.
	$apiKey = ( Contact::get( FALSE )
	                   ->addSelect( 'api_key' )
	                   ->addWhere( 'id', '=', $contactID )
	                   ->execute() )[0]['api_key'];

	if ( ! $apiKey ) {
		// Otherwise generate and save a key as URL-safe random base64 - 18 bytes = 24 characters
		$apiKey = str_replace( [ '+', '/', '=' ], [
			'-',
			'_',
			'',
		], base64_encode( random_bytes( 18 ) ) );
		Contact::update( FALSE )
		       ->addValue( 'api_key', $apiKey )
		       ->addWhere( 'id', '=', $contactID )
		       ->execute();
	}

	return $apiKey;
}

/**
 * Find and replace {civicrm_fp.*} and {civicrm_api_key} merge tags
 *
 * @param string $text
 * @param array $form
 * @param array $entry
 * @param bool $url_encode
 * @param bool $esc_html
 * @param bool $nl2br
 * @param string $format
 *
 * @return string
 */
function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
	if ( ( strpos( $text, '{civicrm_api_key}' ) !== FALSE ) && ( $apiKey = get_api_key() ) ) {
		$text = str_replace( '{civicrm_api_key}', $apiKey, $text );
	}

	$gf_civicrm_site_key_merge_tag = '{gf_civicrm_site_key}';
	if ( strpos( $text, $gf_civicrm_site_key_merge_tag ) !== false ) {
		$gf_civicrm_site_key = FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_site_key' );
		$text = str_replace( $gf_civicrm_site_key_merge_tag, $gf_civicrm_site_key, $text );
	}

	$gf_civicrm_api_key_merge_tag = '{gf_civicrm_api_key}';
	if ( strpos( $text, $gf_civicrm_api_key_merge_tag ) !== false ) {
		$gf_civicrm_api_key = FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_api_key' );
		$text = str_replace( $gf_civicrm_api_key_merge_tag, $gf_civicrm_api_key, $text );
	}

	// TODO - This may pass in multiple options
	/*
	return preg_replace_callback(
		'{ {civicrm_fp(?:_default)? \. ([[:alnum:]_]+) \. ([[:alnum:]_]+) } }x',
		'GFCiviCRM\fp_tag_default',
		$text,'',true

	);
	*/
	$text = preg_replace_callback(
		'{ {civicrm_fp(?:_default)? \. ([[:alnum:]_]+) \. ([[:alnum:]_]+) } }x',
		'GFCiviCRM\fp_tag_default',
		$text
	);
	return $text;
}

add_filter( 'gform_custom_merge_tags', 'GFCiviCRM\compose_merge_tags', 10, 1 );

add_filter( 'gform_replace_merge_tags', 'GFCiviCRM\replace_merge_tags', 10, 7 );

define( 'GF_CIVICRM_FIELDS_ADDON_VERSION', get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'] );

add_action( 'gform_loaded', 'GFCiviCRM\addon_bootstrap', 5 );

function addon_bootstrap() {

	if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}

	require_once( 'class-gf-civicrm-fields.php' );
    require_once( 'includes/class-gf-civicrm-export-addon.php' );

	GFAddOn::register( 'GFCiviCRM\FieldsAddOn' );
    GFAddOn::register( 'GFCiviCRM\ExportAddOn' );
}

/**
 * Fix the counter for input fields to have a max length of 255, CiviCRM's limit.
 *
 * GFCV-89
 */
add_filter( 'gform_counter_script', 'GFCiviCRM\set_text_input_counter', 10, 5 );
function set_text_input_counter( $script, $form_id, $input_id, $max_length, $field ) {
	if ($max_length > 255) {
		$max_length = 255;
	}

    $displayFormat = esc_js( __( '#input of #max max characters', 'gravityforms' ) );

    $script = <<<EOJS
		if(!jQuery('#{$input_id}+.ginput_counter').length){
			jQuery('#{$input_id}').textareaCount({
		    	'maxCharacterSize': {$max_length},
		    	'originalStyle': 'ginput_counter gfield_description',
		    	'displayFormat' : '{$displayFormat}'
		    });
		    jQuery('#{$input_id}').next('.ginput_counter').attr('aria-live','polite');
		};
	EOJS;
    return $script;
}

/**
 * Replace the default countries list with CiviCRM's list.
 *
 * @param array $choices
 *
 */
add_filter( 'gform_countries', 'GFCiviCRM\address_replace_countries_list' );
function address_replace_countries_list( $choices ) {
	$replace = array();

	try {
		$countries = \Civi\Api4\Country::get(FALSE)
			->addSelect('name', 'iso_code')
			->execute();

		foreach ($countries as $country) {
			$replace[] = __( $country["name"], 'gf-civicrm-formprocessor' );
		}
	} catch ( \CRM_Core_Exception $e ) {
		// Could not retrieve CiviCRM countries list
		// Fallback to the original set of choices
		return $choices;
	}

	return $replace;
}

// Ensure that other WordPress plugins have not lowered the curl timeout which impacts Gravity Forms webhook requests
function webhooks_request_args( $request_args, $feed, $entry, $form ) {
    // Set timeout to 10 seconds
	$request_args['timeout'] = 10000;

	return $request_args;
}

function validateChecksumFromURL( $cid_param = 'cid', $cs_param = 'cs' ): int|null {
	$contact_id = rgget( $cid_param );
	$checksum   = rgget( $cs_param );

	if ( empty( $contact_id ) || empty( $checksum ) ) {
		return NULL;
	}

    $validator = Contact::validateChecksum( FALSE )
                        ->setContactId( $contact_id )
                        ->setChecksum( $checksum )
                        ->execute()
                        ->first();

    if ( ! $validator['valid'] ) {
        throw new \CRM_Core_Exception('Invalid checksum');
    } else {
        return $contact_id;
    }
}

add_filter( 'gform_webhooks_request_args', 'GFCiviCRM\webhooks_request_args', 10, 4 );

add_action( 'admin_notices', function() {
	if ( class_exists( 'GFAPI' ) && class_exists( 'GFCiviCRM\FieldsAddOn' ) ) {
		echo FieldsAddOn::get_instance()->warn_auth_checksum();
	}
} );

add_action( 'gform_admin_error_messages', function( $messages ) {
	if ( class_exists( 'GFCiviCRM\FieldsAddOn' ) ) {
		$message = FieldsAddOn::get_instance()->warn_auth_checksum( '%s' );
		if ( $message ) {
			$messages[] = $message;
		}
	}

	return $messages;
} );
