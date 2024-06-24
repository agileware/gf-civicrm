<?php
/**
 * Plugin Name: Gravity Forms CiviCRM Integration
 * Plugin URI: https://bitbucket.org/agileware/gf-civicrm
 * Description: Extends Gravity Forms to get option lists and defaults from linked CiviCRM Form Processors
 * Author: Agileware
 * Author URI: https://agileware.com.au
 * Version: 1.7.0
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
use CRM_Core_Exception;
use GFCommon;
use GFAddon;
use function rgar;

const BEFORE_CHOICES_SETTING = 1350;

define( 'GF_CIVICRM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_CIVICRM_FIELDS_ADDON_VERSION', get_file_data( __FILE__, [ 'Version' => 'Version' ] )[ 'Version' ] );

// Load wpcmrf integration
add_action( 'gform_loaded', 'GFCiviCRM\gf_civicrm_wpcmrf_bootstrap', 5 );
function gf_civicrm_wpcmrf_bootstrap() {
	require_once( GF_CIVICRM_PLUGIN_PATH . '/includes/class-gf-civicrm-exception.php' );
	require_once( 'gf-civicrm-wpcmrf.php' );
}

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
			
			// Check if a CiviCRM installation exists
			if ( check_civicrm_installation()['is_error'] ) {
				break;
			}

			$profile_name = get_rest_connection_profile( $form );

			[ 'option_group' => $option_group, 'processor' => $processor, 'field_name' => $field_name ] = $matches;

			$options        = [];
			$default_option = NULL;

			if ( $option_group ) {
				/**
				 * 
				 * @FIXME
				 * 
				 * 	- What are we even using this for? Implementation disappeared.
				 * 	- Reduce number of API calls here by just getting a reference to the option group id somewhere?
				 * 
				 */
				// Get the option group id
				$api_params = array(
					'name' => $option_group,
					'return' => array('id'), // Specify the fields to return
				);
				$option_group_id = formprocessor_api_wrapper($profile_name, 'OptionGroup', 'get', $api_params, ['limit' => 1])['values'];

				// Then get the Option Group Values attached to that id
				$api_params = array(
					'option_group_id' => array_key_first( $option_group_id ),
					'is_active' => true,
					'return' => array('value', 'label', 'is_default'), // Specify the fields to return
				);
				$api_options = array(
					'check_permissions' => 0, // Set check_permissions to false
					'sort' => 'weight ASC',
					'limit' => 0,
				);
				$options = formprocessor_api_wrapper($profile_name, 'OptionValue', 'get', $api_params, $api_options);
			} elseif ( $processor && $field_name ) {
				try {
					if ( ! isset( $civi_fp_fields[ $processor ] ) ) {
						$api_params = array(
							'api_action' => $processor,
						);
						$api_options = array(
							'check_permissions' => 0, // Set check_permissions to false
							'limit' => 0,
						);						
						$civi_fp_fields[ $processor ] = formprocessor_api_wrapper($profile_name, 'FormProcessor', 'getfields', $api_params, $api_options)['values'] ?? [];
					}

					$default_option = fp_tag_default( [
						$field->civicrmOptionGroup,
						$processor,
						$field_name,
					], NULL, TRUE );

					$field->choices = [];
					if ( $field->type == 'checkbox' ) {
						$field->inputs = [];
					}
					$i = 0;

					foreach ( $civi_fp_fields[ $processor ][ $field_name ]['options'] ?? [] as $value => $label ) {
						$field->choices[] = [
							'text'       => $label,
							'value'      => $value,
							'isSelected' => ( ( is_array( $default_option ) && in_array( $value, $default_option ) ) || ( $value == $default_option ) ),
						];
						if ( $field->type == 'checkbox' ) {
							$field->inputs[] = [
								// Avoid multiples of 10, allegedly these are problematic
								'id'    => $field->id . '.' . ( ++$i % 10? $i : ++$i ),
								'label' => $label,
							];
						}
					}

				} catch ( CiviCRM_API3_Exception $e ) {
					// Couldn't get form processor instance, don't try to set options
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

/**
 * Adds custom merge tags to Insert Merge tags dropdowns.
 */
function compose_merge_tags ( $merge_tags ) {
	try {
		$profile_name = get_rest_connection_profile();
		$processors = formprocessor_api_wrapper($profile_name, 'FormProcessorInstance', 'get', ['sequential' => 1], ['limit' => 0])['values'];
		
		foreach ( $processors as ['inputs' => $inputs, 'name' => $pname, 'title' => $ptitle] ) {
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
		$json_decoded = [];

		$multi_json = (bool) FieldsAddOn::get_instance()->get_plugin_setting( 'civicrm_multi_json' );

		/** @var \GF_Field $field */
		foreach ( $form['fields'] as $field ) {
			if ( property_exists( $field, 'storageType' ) && $field->storageType == 'json' ) {
				$json_decoded[ $field['id'] ] = json_decode( $entry[ $field['id'] ] );
			} elseif (
				! empty( $multi_json ) &&  // JSON encoding selected in settings
				( is_a( $field, 'GF_Field_Checkbox' ) || is_a( $field, 'GF_Field_MultiSelect' ) ) // Multi-value field
			) {
				$json_decoded[ $field->id ] = fix_multi_values( $field, $entry );
			}
		}
		foreach ( $feed['meta']['fieldValues'] as $field_value ) {
			if ( ( ! empty( $field_value['custom_key'] ) ) && ( $value = $json_decoded[ $field_value['value'] ] ?? NULL ) ) {
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
	$profile_name = get_rest_connection_profile( $form_id );

	// Check if a CiviCRM installation exists
	if ( check_civicrm_installation()['is_error'] ) {
		return;
	}

	switch ( $position ) {
		case BEFORE_CHOICES_SETTING:
			$api_params = array(
				'return' => array( 'name', 'title' ), // Specify the fields to return
			);
			$api_options = array(
				'check_permissions' => 0, // Set check_permissions to false
				'sort' => 'title ASC',
				'limit'	=> 0,
			);
			$option_groups = formprocessor_api_wrapper( $profile_name, 'OptionGroup', 'get', $api_params, $api_options )['values'];

			try {
				$form_processors = formprocessor_api_wrapper($profile_name, 'FormProcessorInstance', 'get', ['sequential' => 1], ['limit' => 0])['values'];

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

	$profile_name = get_rest_connection_profile();

	// Check if a CiviCRM installation exists
	if ( check_civicrm_installation()['is_error'] ) {
		return $result;
	}

	if ( ! isset( $defaults[ $processor ] ) ) {
		try {
			// Fetch Form Processor options directly from the GET parameters.
			
			$api_params = array(
				'api_action' => $processor,
			);
			$api_options = array(
				'check_permissions' => 1, // Set check_permissions to false
				'limit'	=> 0,
				'cache' => NULL,
			);
			// Get the cid
			$fields = formprocessor_api_wrapper( $profile_name, 'FormProcessorDefaults', 'getfields', $api_params, $api_options, $api_version = '3' );

			foreach ( array_keys( $fields['values'] ) as $key ) {
				if ( ! empty( $_GET[ $key ] ) ) {
					$api_params[ $key ] = $_GET[ $key ];
				}
			}

			// Get field values
			$defaults[ $processor ] = formprocessor_api_wrapper( $profile_name, 'FormProcessorDefaults', $processor, $api_params, $api_options, $api_version = '3' );
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
	while ( is_array( $result ) ) {
		$result = reset( $result );
	}

	return $result;
}

/**
 * Find or generate API key for the current user.
 */
function get_api_key() {
	// Check if a CiviCRM installation exists
	if ( check_civicrm_installation()['is_error'] ) {
		return null;
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

	return preg_replace_callback(
		'{ {civicrm_fp(?:_default)? \. ([[:alnum:]_]+) \. ([[:alnum:]_]+) } }x',
		'GFCiviCRM\fp_tag_default',
		$text
	);
}

add_filter( 'gform_custom_merge_tags', 'GFCiviCRM\compose_merge_tags', 10, 1 );

add_filter( 'gform_replace_merge_tags', 'GFCiviCRM\replace_merge_tags', 10, 7 );

add_action( 'gform_loaded', 'GFCiviCRM\fields_addon_bootstrap', 5 );

function fields_addon_bootstrap() {

	if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}

	require_once( 'class-gf-civicrm-fields.php' );

	GFAddOn::register( 'GFCiviCRM\FieldsAddOn' );
}

/**
 * Validate address inputs according to CiviCRM rules.
 *
 * @param array $result
 * @param string|array $value
 * @param \GF_Form $form
 * @param \GF_Field $field
 *
 */
add_filter( 'gform_field_validation', 'GFCiviCRM\address_validation', 10, 4 );
function address_validation( $result, $value, $form, $field ) {
    // Validate input before attempting to enter into CiviCRM
    if ( 'address' === $field->type ) {
		// GF Address fields have set input ids for each inner field
		$address_field_keys = array(
			'street_address' 		 => '.1',
			'supplemental_address_1' => '.2',
			'city' 					 => '.3',
			'state_province_id' 	 => '.4',
			'postal_code' 			 => '.5',
			'country_id' 			 => '.6',
		);

		// Field labels for error messaging
		// Keys should correspond to the ids as seen in $address_field_keys
		$field_labels = array();
		$field_labels[] = $field['label']; // Address field label
		foreach ( $field['inputs'] as $address_field_key => $address_field_value ) {
			$field_labels[$address_field_key + 1] = isset( $address_field_value['customLabel'] ) ? $address_field_value['customLabel'] : $address_field_value['label'];
		}

		$error_messages = "";

		// Get input values
		$street   = rgar( $value, $field->id . $address_field_keys['street_address'] );
        $street2  = rgar( $value, $field->id . $address_field_keys['supplemental_address_1'] );
        $city     = rgar( $value, $field->id . $address_field_keys['city'] );
        $state    = rgar( $value, $field->id . $address_field_keys['state_province_id'] );
        $postcode = rgar( $value, $field->id . $address_field_keys['postal_code'] );
        $country  = rgar( $value, $field->id . $address_field_keys['country_id'] );

		$profile_name = get_rest_connection_profile( $form );
		$api_options = array(
			'check_permissions' => 0, // Set check_permissions to false
			'limit' => 0,
		);

		// Get country_id and state_id
		$country_id = null;
		$state_id = null;

		try {
			$api_params = array(
				'name' => $country,
				'return' => array('id'), // Specify the fields to return
			);
			$country_id = formprocessor_api_wrapper($profile_name, 'Country', 'get', $api_params, $api_options)['id'];
		} catch ( \CRM_Core_Exception $e ) {
			// Only throw a validation error if the field is required
			if ( $field->isRequired ) {
				// No country ID found
				$result['is_valid'] = false;
				$error_messages .= '<li>' . __( 'Invalid '. $field_labels[6] . '.', 'gf-civicrm-formprocessor' ) . '</li>';
			}
		}

		// State depends on country_id being valid
		if ( !is_null($country_id) && !empty($state) ) {
			$is_abbrev = false;

			// Check for abbreviation. If none found, check for state name.
			try {
				$api_params = array(
					'country_id'	=> $country_id,
					'abbreviation'	=> $state,
					'return' => array('id'), // Specify the fields to return
				);
				$state_id = formprocessor_api_wrapper($profile_name, 'StateProvince', 'get', $api_params, $api_options)['id'];

				$is_abbrev = true;
			} catch ( \CRM_Core_Exception $e ) {
				// Do nothing yet
			}

			if ( !$is_abbrev || is_null( $state_id ) ) {
				try {
					$api_params = array(
						'country_id'	=> $country_id,
						'name'			=> $state,
						'return' => array('id'), // Specify the fields to return
					);
					$state_id = formprocessor_api_wrapper($profile_name, 'StateProvince', 'get', $api_params, $api_options)['id'];
				} catch ( \CRM_Core_Exception $e ) {
					// Only throw a validation error if the field is required
					if ( $field->isRequired ) {
						// No state_id found
						$result['is_valid'] = false;
						$error_messages .= '<li>' . __( 'Invalid '. $field_labels[4] . '.', 'gf-civicrm-formprocessor' ) . '</li>';
					}
				}
			}
		}

		// Validate the whole address
		$api_params = array(
			'action'					=> 'create', // api action
			'contact_id' 				=> 1, // any contact
			'location_type_id' 			=> 5, // Billing location_type_id is always 5
			'street_address' 			=> $street,
			'supplemental_address_1' 	=> $street2,
			'city' 						=> $city,
			'state_province_id' 		=> $state_id,
			'postal_code' 				=> $postcode,
			'country_id' 				=> $country_id,
		);
		$validate = formprocessor_api_wrapper($profile_name, 'Address', 'validate', $api_params, $api_options);

		// Build the error message
		if ( isset( $validate['values'] ) && count( $validate['values'][0] ) > 0 ) {
			foreach( $validate['values'][0] as $field_id => $error ){
				$field->set_input_validation_state( $address_field_keys[$field_id], false );
				$result['is_valid'] = false;
				$error_messages .= '<li>' . $error['message'] . '</li>';
			}
		}

		// Output error messages
		if ( !$result['is_valid'] ) {
			$result['message']  = empty( $field->errorMessage ) 
				? 'Invalid inputs found in ' . $field_labels[0] . '. Please review the following fields before submission:<ul>' . $error_messages . '</ul>'
				: $field->errorMessage;
		}
    }

    return $result;
}

/**
 * Replace the default countries list with CiviCRM's list.
 *
 * @param array $choices
 * 
 * Note the Address field's Country subfield is overridden with CiviCRM's list in class-gf-civicrm-address-field.php.
 * However, this should also override it for all references to Countries in Gravity Forms (e.g. in Address Types).
 *
 */
add_filter( 'gform_countries', 'GFCiviCRM\address_replace_countries_list' );
function address_replace_countries_list( $choices ) {
	$replace = array();

	$profile_name = get_rest_connection_profile();
	$api_options = array(
		'check_permissions' => 0, // Set check_permissions to false
		'limit' => 0,
	);

	try {
		$api_params = array(
			'return' => array( 'name', 'iso_code' ), // Specify the fields to return
		);
		$countries = formprocessor_api_wrapper($profile_name, 'Country', 'get', $api_params, $api_options)['values'];
	
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

/**
 * Replace dropdown field null values with blank "" on submission.
 */
add_action( 'gform_pre_submission', 'GFCiviCRM\handle_optional_select_field_values' );
function handle_optional_select_field_values( $form ) {
	// Get the input id for multi select type fields
	$fields = $form['fields'];

	foreach ($fields as $field) {
		if ( 'select' === $field->inputType ) {
			$field_id = $field->id;
			$value = $_POST['input_' . $field_id];

			// Fix optional dropdown fields saving no selection as "- None -"
			if ( $value == "- None -" ) {
				$_POST['input_' . $field_id] = "";
			}
		}
	}
}

// Ensure that other WordPress plugins have not lowered the curl timeout which impacts Gravity Forms webhook requests
function webhooks_request_args( $request_args, $feed, $entry, $form ) {
    // Set timeout to 10 seconds
	$request_args['timeout'] = 10000;

	return $request_args;
}

add_filter( 'gform_webhooks_request_args', 'GFCiviCRM\webhooks_request_args', 10, 4 );