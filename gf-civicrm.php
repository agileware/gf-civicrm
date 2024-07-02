<?php
/**
 * Plugin Name: Gravity Forms CiviCRM Integration
 * Plugin URI: https://bitbucket.org/agileware/gf-civicrm
 * Description: Extends Gravity Forms to get option lists and defaults from linked CiviCRM Form Processors
 * Requires Plugins: gravityforms
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
use CRM_Core_Exception;
use GFCommon;
use GFAddon;
use function rgar;

const BEFORE_CHOICES_SETTING = 1350;

define( 'GF_CIVICRM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_CIVICRM_FIELDS_ADDON_VERSION', get_file_data( __FILE__, [ 'Version' => 'Version' ] )[ 'Version' ] );

register_activation_hook(__FILE__, 'GFCiviCRM\check_plugin_dependencies');

/**
 * Either civicrm or wpcmrf plugins must be active.
 * This is not supported by the Dependencies header, so implement our own check.
 */
function check_plugin_dependencies() {
	// Check if CiviCRM or WP CMRF plugins are active
	if ( ! is_plugin_active( 'civicrm/civicrm.php' ) && ! is_plugin_active( 'connector-civicrm-mcrestface/wpcmrf.php' ) ) {
        $notice = sprintf(
        /* translators: 1: this plugin name, 2, 3: required plugin names */
            esc_html__( 'Before activating %1$s, you must first activate either %2$s or %3$s.', 'gf-civicrm' ),
            'Gravity Forms CiviCRM Integration',
            '<a href="https://wordpress.org/plugins/connector-civicrm-mcrestface/">Connector to CiviCRM with CiviMcRestFace</a>',
            'CiviCRM' );

        // Show an error message and exit immediately
		\wp_die( $notice, __( 'Plugin Activation Error', 'gf-civicrm' ), [ 'back_link' => TRUE ] );
	}
}

// Load wpcmrf integration
add_action( 'gform_loaded', 'GFCiviCRM\gf_civicrm_wpcmrf_bootstrap', 5 );

function gf_civicrm_wpcmrf_bootstrap() {
	require_once( GF_CIVICRM_PLUGIN_PATH . 'includes/class-gf-civicrm-exception.php' );
	require_once( GF_CIVICRM_PLUGIN_PATH . 'includes/gf-civicrm-wpcmrf.php' );
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
				$api_params = [
					'name' 		=> $option_group,
					'return' 	=> ['id'], // Specify the fields to return
				];
				$option_group_id = api_wrapper($profile_name, 'OptionGroup', 'get', $api_params, [ 'limit' => 1], '4')['values'];

				// Then get the Option Group Values attached to that id
				$api_params = [
					'option_group_id' 	=> array_key_first( $option_group_id ),
					'is_active' 		=> true,
					'return' 			=> ['value', 'label', 'is_default'], // Specify the fields to return
				];
				$api_options = [
					'check_permissions' => 0, // Set check_permissions to false
					'sort' 				=> 'weight ASC',
					'limit' 			=> 0,
				];
				$options = api_wrapper($profile_name, 'OptionValue', 'get', $api_params, $api_options);
			} elseif ( $processor && $field_name ) {
				try {
					if ( ! isset( $civi_fp_fields[ $processor ] ) ) {
						$api_params = [ 
							'api_action' => $processor 
						];
						$api_options = [
							'check_permissions' => 0, // Set check_permissions to false
							'limit' 			=> 0,
						];						
						$civi_fp_fields[ $processor ] = api_wrapper($profile_name, 'FormProcessor', 'getfields', $api_params, $api_options)['values'] ?? [];
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

				} catch ( CRM_Core_Exception $e ) {
					// Couldn't get form processor instance, don't try to set options
				}
			}

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

/**
 * Adds custom merge tags to Insert Merge tags dropdowns.
 */
function compose_merge_tags ( $merge_tags ) {
	try {
		$profile_name = get_rest_connection_profile();
		$processors = api_wrapper($profile_name, 'FormProcessorInstance', 'get', [ 'sequential' => 1], [ 'limit' => 0])['values'];
		
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
			$api_params = [
				'return' => ['name', 'title'], // Specify the fields to return
			];
			$api_options = [
				'check_permissions' => 0, // Set check_permissions to false
				'sort' 				=> 'title ASC',
				'limit'				=> 0,
			];
			$option_groups = api_wrapper( $profile_name, 'OptionGroup', 'get', $api_params, $api_options )['values'] ?? [];

			try {
				$form_processors = api_wrapper($profile_name, 'FormProcessorInstance', 'get', [ 'sequential' => 1], [ 'limit' => 0])['values'] ?? [];

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
			} catch ( CRM_Core_Exception $e ) {
				// Form processor extension may not be installed, ignore
				$form_processors = [];
			}
			?>
			<li class="civicrm_optiongroup_setting field_setting">
				<label for="civicrm_optiongroup_selector">
					<?php esc_html_e( 'CiviCRM Source', 'gf-civicrm' ); ?>
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
			
			$api_version = '3';
			$api_params = array(
				'api_action' => $processor,
			);
			$api_options = array(
				'check_permissions' => 1, // Set check_permissions to false
				'limit'	=> 0,
				'cache' => NULL,
			);
			// Get the cid
			$fields = api_wrapper( $profile_name, 'FormProcessorDefaults', 'getfields', $api_params, $api_options, $api_version );

			foreach ( array_keys( $fields['values'] ) as $key ) {
				if ( ! empty( $_GET[ $key ] ) ) {
					$api_params[ $key ] = $_GET[ $key ];
				}
			}

			// Get field values
			$defaults[ $processor ] = api_wrapper( $profile_name, 'FormProcessorDefaults', $processor, $api_params, $api_options, $api_version );
		} catch ( CRM_Core_Exception $e ) {
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
 *
 * This function is implemented without CMRF support, as it does not make sense in that context
 */
function get_api_key() {
	// Leave early if directly connected to CiviCRM.
	if ( ! ( function_exists( 'civicrm_initialize' ) && civicrm_initialize() ) ) {
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
 * Fix the counter for input fields to have a max length of 255, CiviCRM's limit.
 * 
 * GFCV-89
 */
add_filter( 'gform_counter_script', 'GFCiviCRM\set_text_input_counter', 10, 5 );
function set_text_input_counter( $script, $form_id, $input_id, $max_length, $field ) {
	if ($max_length > 255) {
		$max_length = 255;
	}

    $script =
		"if(!jQuery('#{$input_id}+.ginput_counter').length){jQuery('#{$input_id}').textareaCount(" .
		"    {'maxCharacterSize': {$max_length}," .
		"    'originalStyle': 'ginput_counter gfield_description'," .
		"    'displayFormat' : '#input " . esc_js( __( 'of', 'gravityforms' ) ) . ' #max ' . esc_js( __( 'max characters', 'gravityforms' ) ) . "'" .
		"    });" . "jQuery('#{$input_id}').next('.ginput_counter').attr('aria-live','polite');}";
    return $script;
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
	$replace = [];

	$profile_name = get_rest_connection_profile();
	$api_options = [
		'check_permissions' => 0, // Set check_permissions to false
		'limit' => 0,
	];

	try {
		$api_params = [
			'return' => ['name', 'iso_code',] // Specify the fields to return
		];
		$countries = api_wrapper($profile_name, 'Country', 'get', $api_params, $api_options);
	
		if ( isset( $countries['is_error'] ) && $countries['is_error'] != 0  ) {
			throw new \GFCiviCRM_Exception( $countries['error_message'] );
		} else {
			$countries = $countries['values'];
		}

		foreach ($countries as $country) {
			$replace[] = __( $country["name"], 'gf-civicrm' );
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