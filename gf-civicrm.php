<?php
/**
 * Plugin Name: Gravity Forms CiviCRM Integration
 * Plugin URI: https://github.com/agileware/gf-civicrm
 * Description: Extends Gravity Forms to get option lists and defaults from linked CiviCRM Form Processors in a local CiviCRM installation
 * Requires plugins: gravityforms
 * Author: Agileware
 * Author URI: https://agileware.com.au
 * Version: 2.0.0-alpha-4
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

use GFAPI;
use GFFormsModel;

const BEFORE_CHOICES_SETTING = 1350;

define( 'GF_CIVICRM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'GF_CIVICRM_PLUGIN_SLUG', plugin_basename( __FILE__ ) );
define( 'GF_CIVICRM_PLUGIN_GITHUB_REPO', 'agileware/gf-civicrm' ); // GitHub username and repo

add_action('plugins_loaded', function() {
	// Include the updater class
	require_once GF_CIVICRM_PLUGIN_PATH . 'includes/class-gf-civicrm-upgrader.php';

	// Initialize the updater
	$updater = new Upgrader( __FILE__ );
	$updater->init();
});

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

function compose_merge_tags ( $merge_tags, $form_id ) {
	try {
		$profile_name = get_rest_connection_profile( $form_id );
		$form_processors = api_wrapper( $profile_name, 'FormProcessorInstance', 'get', [], [] ) ?? [];

		$form = GFAPI::get_form( $form_id );
		$form_settings    = FieldsAddOn::get_instance()->get_form_settings( $form );
		$default_fp_value = rgar( $form_settings, 'default_fp' );

		if ( $default_fp_value ) {
			$default_fp_options = reset( array_filter( $form_processors, fn($fp) => $fp['name'] === $default_fp_value ) );

			foreach ($default_fp_options['inputs'] as ['name' => $iname, 'title' => $ititle]) {
				$merge_tags[] = [
					'label' => sprintf( __( '%s / %s / %s', 'gf-civicrm' ), 'Default', $default_fp_options['title'], $ititle ),
					'tag'   => "{civicrm_fp.default_fp.{$iname}}",
				];
			}
		}

		foreach (
			$form_processors
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
 * Replaces the default_fp tag with the value of the default form processor form setting, if it exists.
 * Do this in pre_render for the $form context, which is not passed to gform_replace_merge_tags for default values.
 * 
 * @param $form
 * 
 * @return mixed
 */
function replace_default_fp( $form ) {
	if ( !class_exists( 'GFCiviCRM\FieldsAddOn' ) ) {
		return $form; // do nothing
	}

	$form_settings    = FieldsAddOn::get_instance()->get_form_settings( $form );
	$default_fp_value = rgar( $form_settings, 'default_fp' );

	// Only proceed if the setting has a value.
	if ( empty( $default_fp_value ) ) {
		return $form;
	}

	/**
	 * A closure that takes a string by reference and replaces the
	 * 'default_fp' placeholder with the actual value from form settings.
	 *
	 * @param ?string &$string_to_update The string to process.
	 */
	$replacer = function ( ?string &$string_to_update ) use ( $default_fp_value ) {
		if ( empty( $string_to_update ) || strpos( $string_to_update, '.default_fp.' ) === false ) {
			return;
		}

		$string_to_update = preg_replace_callback(
			'/{ (civicrm_fp(?:_default)?) \. default_fp \. ([[:alnum:]_]+) }/x',
			function ( $matches ) use ( $default_fp_value ) {
				// Reconstruct the merge tag with the real value.
				return sprintf(
					'{%1$s.%2$s.%3$s}',
					$matches[1],
					$default_fp_value,
					$matches[2]
				);
			},
			$string_to_update
		);
	};

	foreach ( $form['fields'] as &$field ) {
		// Process the default value for the main field object.
		if ( isset( $field->defaultValue ) ) {
			$replacer( $field->defaultValue );
		}

		if ( 'html' === $field->type ) {
			$replacer( $field->content );
		}

		// Process default values for address sub-fields (which are in an array).
		if ( 'address' === $field->type && ! empty( $field->inputs ) ) {
			foreach ( $field->inputs as &$input ) {
				// The defaultValue for sub-fields is an array key.
				if ( isset( $input['defaultValue'] ) ) {
					$replacer( $input['defaultValue'] );
				}
			}
		}
	}

	return $form;
}
add_filter( 'gform_pre_render', 'GFCiviCRM\replace_default_fp', 10, 1 );

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
    	$number = str_replace([',', '.'], '', $number);
	}

	// Convert to float cast as a string because webhook will complain otherwise
	return (string) floatval( $number );
}

/*
* Extend the maximum attempts for webhook calls, so Gravity Forms does not give up if unable to connect on first go
*/

add_filter( 'gform_max_async_feed_attempts', 'GFCiviCRM\custom_max_async_feed_attempts', 10, 5 );

function custom_max_async_feed_attempts( $max_attempts, $form, $entry, $addon_slug, $feed ) {
    if ( $addon_slug == 'gravityformswebhooks' ) {
        $max_attempts = 3;
    }
    return $max_attempts;
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

		$feed_keys = [];

		// Find the field ids to process
		foreach( $feed[ 'meta' ][ 'fieldValues' ] as $fv ) {
			$feed_keys[ $fv[ 'value' ] ] = $fv[ 'custom_key' ];
		}

		/** @var \GF_Field $field */
		foreach ( $form['fields'] as $field ) {
			// Skip if not part of entry meta
			if( !$feed_keys[ $field->id ] ) {
				continue;
			}

			// Send multi-value fields encode in json instead of comma separated
			if ( $feed['meta']['requestFormat'] === 'json' ) {
				if ( property_exists( $field, 'storageType' ) && $field->storageType == 'json' ) {
					$rewrite_data[ $field['id'] ] = json_decode( $entry[ $field['id'] ] );
				} elseif (
					! empty( $multi_json ) &&  // JSON encoding selected in settings
					( is_a( $field, 'GF_Field_Checkbox' ) || is_a( $field, 'GF_Field_MultiSelect' ) ) // Multi-value field
				) {
					$rewrite_data[ $field->id ] = fix_multi_values( $field, $entry );
				}
			}

			/*
			* Custom Price, Product fields send the value in $ 50.00 format which is problematic
			* @TODO If the $feed['meta']['fieldValues'][x] field has a value=gf_custom then custom_value will contain something like {membership_type:83:price} - this requires new logic extract the field ID. Will not contain the usual field ID.
			*/
			if ( is_a( $field, 'GF_Field_Price' ) && $field->inputType == 'price' && isset( $entry[ $field->id ] ) ) {
				$rewrite_data[ $field->id ] = convertInternationalCurrencyToFloat( $entry[ $field->id ] );
			}

			// URL encode file url parts.  The is mostly because PHP does not count URLs with UTF-8 in them as valid.
			if( is_a( $field, 'GF_Field_FileUpload' ) ) {
				// Assume the scheme + domain is already encoded, extract path part
				if(preg_match('{ (^ .+? ://+ .+? / ) ( .+ ) }x', $entry[ $field->id ], $matches)) {
					[, $base, $path] = $matches;

					// Each path, query, and fragment part, should be passed through urlencode
					$path = preg_replace_callback( '{ ( [^/?#&]+ ) }x', fn($part) => urlencode( $part[1] ), $path );

					// Recombine for the rewritten data
					$rewrite_data[ $field->id ] = $base . $path;
				}
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
 * Add setting for CiviCRM Source to Gravity Forms editor standard settings.
 * Allows you to select a Form Processor input as the source for values for a Gravity Forms field.
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
				'sort'              => 'title ASC',
				'limit'             => 0,
			];
      		$option_groups = api_wrapper( $profile_name, 'OptionGroup', 'get', $api_params, $api_options ) ?? [];

			try {
        		$form_processors = api_wrapper($profile_name, 'FormProcessorInstance', 'get', [ 'sequential' => 1], [ 'limit' => 0]) ?? [];

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

			// Build the list of options for the default_fp tag.
			// If the default_fp form setting has a value, populate with the values for the defined form processor.
			// Otherwise, do not add anything to the list of options.
			$form = GFAPI::get_form( $form_id );
			$form_settings    = FieldsAddOn::get_instance()->get_form_settings( $form );
			$default_fp_value = rgar( $form_settings, 'default_fp' );

			if ( $default_fp_value ) {
				$default_fp_options = reset(array_filter( $form_processors, fn($fp) => $fp['name'] === $default_fp_value ));
			}

			?>
			<li class="civicrm_optiongroup_setting field_setting">
				<label for="civicrm_optiongroup_selector">
					<?php esc_html_e( 'CiviCRM Source', 'gf-civicrm' ); ?>
				</label>
				<select id="civicrm_optiongroup_selector"
				        onchange="SetCiviCRMOptionGroup(this)">
					<option value=""><?php esc_html_e( 'None' ); ?></option>
					<?php if ( $default_fp_options ): ?>
						<optgroup label="DEFAULT Form Processor: <?php echo $default_fp_options['title']; ?>">
							<?php foreach ( $default_fp_options['options'] as $pr_name => $pr_title ) {
								echo "<option value=\"civicrm_fp__{$default_fp_options['name']}__{$pr_name}\">{$pr_title}</option>";
							} ?>
						</optgroup>
					<?php endif; ?>
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
function fp_tag_default( $matches, $fallback = '', $multiple = false ) {
  	static $defaults = [];

	$result = $fallback;
	[ , $processor, $field ] = $matches;

	// Check if a CiviCRM installation exists
	if ( check_civicrm_installation()['is_error'] ) {
		return $result;
	}

	$profile_name = get_rest_connection_profile();

	if ( ! isset( $defaults[ $processor ] ) ) {
		try {
			$api_params = [
				'api_action' => $processor,
			];
			$api_options = [
				'check_permissions' => 1, // Set check_permissions to false
				'limit'	=> 0,
				'cache' => NULL,
			];

			// Get the form processor fields
			$fields = api_wrapper( $profile_name, 'FormProcessorDefaults', 'getfields', $api_params, $api_options );

			foreach ( $fields as $value ) {
				if ( ! empty( $_GET[ $value['name'] ] ) ) {
					$api_params[ $value['name'] ] = $_GET[ $value['name'] ];
				}
			}

			// Get field default values
			$defaults[ $processor ] = api_wrapper( $profile_name, 'FormProcessorDefaults', $processor, $api_params, $api_options );
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
	// @TODO - This may be interferring with the setting of multiple values using the default value field, form process merge tag
	while ( is_array( $result ) ) {
		$result = reset( $result );
	}

	return $result;
}

/**
 * Find and replace {civicrm_fp.*}, {gf_civicrm_site_key}, {gf_civicrm_api_key}, and {rest_api_url} merge tags
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
	$gf_civicrm_rest_url_merge_tag = '{gf_civicrm_rest_url}';
	$gf_civicrm_site_key_merge_tag = '{gf_civicrm_site_key}';
	$gf_civicrm_api_key_merge_tag = '{gf_civicrm_api_key}';
	$needs_rest_url  = strpos( $text, $gf_civicrm_rest_url_merge_tag ) !== false;
	$needs_site_key = strpos( $text, $gf_civicrm_site_key_merge_tag ) !== false;
	$needs_api_key  = strpos( $text, $gf_civicrm_api_key_merge_tag ) !== false;

	if ( $needs_rest_url || $needs_site_key || $needs_api_key ) {
		// Only call these once if needed
		$profile_name = get_rest_connection_profile();
		$profiles     = get_profiles();
		$plugin_active = is_plugin_active( 'connector-civicrm-mcrestface/wpcmrf.php' );

		if ( $plugin_active && isset( $profiles[ $profile_name ] ) ) {
			$profile = $profiles[ $profile_name ];
		} else {
			$profile = null;
		}

		if ( $needs_rest_url ) {
			$gf_civicrm_rest_url = $profile && isset( $profile['url'] ) ? $profile['url'] : GFCommon::format_variable_value( rest_url(), $url_encode, $esc_html, $format, $nl2br );
			$text = str_replace( $gf_civicrm_rest_url_merge_tag, $gf_civicrm_rest_url, $text );
		}

		if ( $needs_site_key ) {
			$gf_civicrm_site_key = $profile && isset( $profile['site_key'] ) ? $profile['site_key'] : FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_site_key' );
			$text = str_replace( $gf_civicrm_site_key_merge_tag, $gf_civicrm_site_key, $text );
		}

		if ( $needs_api_key ) {
			$gf_civicrm_api_key = $profile && isset( $profile['api_key'] ) ? $profile['api_key'] : FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_api_key' );
			$text = str_replace( $gf_civicrm_api_key_merge_tag, $gf_civicrm_api_key, $text );
		}
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

add_filter( 'gform_custom_merge_tags', 'GFCiviCRM\compose_merge_tags', 10, 2 );

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
	$replace = [];
	$countries = [];

	if ( $cached_countries_data = get_transient( 'gfcv_civicrm_countries' ) ) {
		$countries = $cached_countries_data;
	} else {
		try {
			/**
			 * DEV NOTE: This is also done in loadCountriesAndStatesData().
			 * TODO: Reduce duplication and pull data from CiviCRM outside of GFCiviCRM::Address_Field.
			 */
			$profile_name = get_rest_connection_profile();

			// Get the list of available countries configured in CiviCRM Settings
      		$api_params = [
				'return' => [ 'countryLimit' ],
			];
			$api_options = [
				'check_permissions' => 0, // Set check_permissions to false
				'limit' => 0,
			];
			$available_countries = api_wrapper( $profile_name, 'Setting', 'get', $api_params, $api_options );
			$available_countries = reset($available_countries);

      		$api_params = [
				'select' => [ 'id', 'name', 'iso_code' ],
				'api.StateProvince.get' => [
					'options' => [
						'limit' => 0, 
						'sort' => "name ASC"
					],
				],
				'options' => [
					'limit' => 0, 
					'sort' => "name ASC"
				],
			];
			if ( !empty( $available_countries['countryLimit'] ) ) {
				$api_params['id'] = [ 'IN' => $available_countries['countryLimit'] ];
			}
      		$api_options = [
				'check_permissions' => 0, // Set check_permissions to false
				'limit' => 0,
			];

			// Get Countries and their States/Provinces from CiviCRM
			$countries_data = api_wrapper( $profile_name, 'Country', 'get', $api_params, $api_options );

			if ( !empty( $countries_data ) ) {
				foreach ( $countries_data as $country ) {
          			$state_province    = $country['api.StateProvince.get']['values'] ?? array();
					$country['states'] = $state_province;
					unset($country['api.StateProvince.get']);

					$countries[] = $country;
				}

				set_transient( 'gfcv_civicrm_countries', $countries );
			}
		} catch ( \CRM_Core_Exception $e ) {
			// Could not retrieve CiviCRM countries list
			// Fallback to the original set of choices
		}
	}

	if ( !empty( $countries ) ) {
		foreach ($countries as $country) {
      		$replace[] = __($country['name'], 'gf-civicrm-formprocessor');
		}
		return $replace;
	}

	return $choices;
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
			if ($value == '- None -') {
				$_POST['input_' . $field_id] = '';
			}
		}
	}
}

function validateChecksumFromURL( $cid_param = 'cid', $cs_param = 'cs' ): int|null {
	$contact_id = rgget( $cid_param );
	$checksum   = rgget( $cs_param );

	if ( empty( $contact_id ) || empty( $checksum ) ) {
    	return null;
	}

	try {
		$profile_name = get_rest_connection_profile();
		
		// Get Payment Processors from CiviCRM
    	$api_params = [
			'id' => $contact_id,
			'checksum' => $checksum ,
		];
		$validator = api_wrapper( $profile_name, 'ContactChecksum', 'validate', $api_params );

		if ( ! $validator[1][0] ) { // checksum validation value
			throw new \CRM_Core_Exception('Invalid checksum');
		}
	} catch ( \CRM_Core_Exception $e ) {
		// TODO Log error?
	}

	return $contact_id;
}

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

/**
 * Handles sending webhook alerts for failures to the alerts email address provided in the GF CiviCRM Settings.
 */
function webhook_alerts( $response, $feed, $entry, $form ) {
	if ( !class_exists( 'GFCiviCRM\FieldsAddOn' ) ) {
		return;
	}

	// Add the webhook response to the entry meta. Supports multiple feeds.
	$current_response = gform_get_meta( $entry['id'], 'webhook_feed_response' );
	if ( empty( $current_response ) ) {
		$current_response = [];
	}

	$error_code = null;
	$error_message 	= '';

	// Get the error message, and log it in the Gravity Forms logs (if enabled)
	if ( is_wp_error( $response ) ) {
		// If its a WP_Error
		$error_code = $response->get_error_code();
    	$error_message = $response->get_error_message();

		// Build the webhook response entry content
    	$webhook_feed_response = [ 
			'date' => current_datetime(),
			'body' => $error_message, 
			'response' => $error_code
		];

		GFCommon::log_debug( __METHOD__ . '(): WP_Error detected.' );
	} else {
		$response_data = $response['body'] ? json_decode($response['body'], true) : '';

		// Build the webhook response entry content
    	$webhook_feed_response = [ 
			'date' => $response['headers']['data']['date'],
			'body' => $response_data, 
			'response' => $response['response']
		];

		if ( is_wp_error( $response ) ) {
			// If its a WP_Error in the webhook feed response
			$error_code = $response->get_error_code();
			$error_message = $response->get_error_message();
	
			GFCommon::log_debug( __METHOD__ . '(): WP_Error detected.' );
		} 
		
		if ( isset( $response['response']['code'] ) && $response['response']['code'] >= 300 && strpos( $response['body'], 'is_error' ) == false ) {
			// If we get an error response
			$response_data = json_decode( $response['body'], true );
			
			$error_code = $response['response']['code'];
			$error_message = $response['response']['message'] . "\n" . ( $response_data['message'] ?? '' );
			
			GFCommon::log_debug( __METHOD__ . '(): Error detected.' );
		}
		
		if ( strpos( $response['body'], 'is_error' ) != false ) {
			// If is_error appears in the response body 
			// This may happen if the webhook response appears as a success, but the response value from the REST url is actually an error.

			// Extract the required values
			$response_data = json_decode( $response['body'], true );
			$is_error = $response_data['is_error'] ?? false;

			// Validate is_error before doing anything
			if ( $is_error ) {
				$error_code = $response_data['error_code'] ?? '';
				$error_message = $response_data['error_message'] ?? '';
			}

			GFCommon::log_debug( __METHOD__ . '(): Error detected.' );
		}
	}

	$current_response[$feed['id']] = $webhook_feed_response;
	gform_update_meta( $entry['id'], 'webhook_feed_response', $current_response );

	// Send an alert email if we have an error code
	if ( $error_code !== null ) {
		GFCommon::log_debug( __METHOD__ . '(): Error Message: ' . $error_message );

		// Do not continue if enable_emails is not true, or if no alerts email has been provided
		$plugin_settings = FieldsAddOn::get_instance()->get_plugin_settings();
    	if ( !isset($plugin_settings['enable_emails']) || !$plugin_settings['enable_emails'] || 
			!isset($plugin_settings['gf_civicrm_alerts_email']) || empty($plugin_settings['gf_civicrm_alerts_email']) ) {
			return;
		}

		// Build the alert email
		$to     		= $plugin_settings['gf_civicrm_alerts_email'];
		$subject 		= sprintf('Webhook failed on %s', get_site_url());
		$request_url 	= $feed['meta']['requestURL'];
		$entry_id 		= $entry['id'];

    	$request_url = apply_filters('gform_replace_merge_tags', $request_url, $form, $entry, false, false, false, 'html');

		$body    = sprintf(
			'Webhook feed on %s failed.' . "\n\n%s%s\n" . 'Feed: "%s" (ID: %s) from form "%s" (ID: %s)' . "\n" . 'Request URL: %s' . "\n" . 'Failed Entry ID: %s',
			get_site_url(),
			$error_code ? "Error Code: " . $error_code . "\n": '', 
			$error_message ? "Error: " . $error_message . "\n": '', 
			$feed['meta']['feedName'], 
			$feed['id'], 
			$form['title'], 
			$form['id'], 
			$request_url,
			$entry_id
    	);

		// Send an email to the nominated alerts email address
		wp_mail( $to, $subject, $body );
	}
}

add_action( 'gform_webhooks_post_request', 'GFCiviCRM\webhook_alerts', 10, 4);

/**
 * Save the webhook request to the Entry.
 * 
 * Request URL is processed before request args. We'll use both filters to build the request
 * data. Supports multiple feeds.
 */
add_filter( 'gform_webhooks_request_url', function ( $request_url, $feed, $entry, $form ) {
	// Add the webhook request to the entry meta
	$current_request = gform_get_meta( $entry['id'], 'webhook_feed_request' );

	if ( $current_request && is_array($current_request) ) {
		$current_request[$feed['id']] = [
			'request_url' => $request_url,
		];
	} else {
		$current_request = [
			$feed['id'] => [
				'request_url' => $request_url,
			],
		];
	}

	gform_update_meta( $entry['id'], 'webhook_feed_request', $current_request );
	return $request_url;
}, 10, 4 );

add_filter( 'gform_webhooks_request_args', function ( $request_args, $feed, $entry, $form ) {
	// Ensure that other WordPress plugins have not lowered the curl timeout which impacts Gravity Forms webhook requests.
	// Set timeout to 10 seconds
	$request_args['timeout'] = 10000;

	// Add the webhook request to the entry meta
	$current_request = gform_get_meta( $entry['id'], 'webhook_feed_request' );
    if (! is_array( $current_request ) ) {
		$current_request = [
			$feed['id'] => [
				'request_url' => $current_request
			]
		];
    }

    $current_request[$feed['id']] = [
      'request_url' => $current_request[$feed['id']]['request_url'] ?? '',
      'request_args' => $request_args,
    ];

	gform_update_meta( $entry['id'], 'webhook_feed_request', $current_request );
	return $request_args;
}, 10, 4 );

/**
 * Register custom Entry meta fields.
 */
add_filter( 'gform_entry_meta', function ( $entry_meta, $form_id ) {
	$entry_meta['webhook_feed_request'] = [
		'label'       => esc_html__( 'Webhook Request', 'gf-civicrm' ),
		'is_numeric'  => false,
		'update_entry_meta_callback' => null, // Optional callback for updating.
		'filter'      => true, // Enable filtering in the Entries UI
	];
	$entry_meta['webhook_feed_response'] = [
		'label'       => esc_html__( 'Webhook Response', 'gf-civicrm' ),
		'is_numeric'  => false,
		'update_entry_meta_callback' => null,
		'filter'      => true,
	];

	return $entry_meta;
}, 10, 2 );

/**
 * Display the webhook feed result as a metabox when viewing an entry.
 */
add_filter( 'gform_entry_detail_meta_boxes', function ( $meta_boxes, $entry, $form ) {
	// Add a new meta box for the webhook request.
	$meta_boxes['webhook_feed_request'] = [
		'title'    => esc_html__( 'Webhook Request', 'gf-civicrm' ),
		'callback' => function( $args ) {
			display_webhook_meta_box( $args, "webhook_feed_request" );
		},
		'context'  => 'normal', // Can be 'normal', 'side', or 'advanced'.
		'priority' => 'low', // Ensure the meta box appears at the bottom of the section.
	];
	// Add a new meta box for the webhook response.
	$meta_boxes['webhook_feed_response'] = [
		'title'    => esc_html__( 'Webhook Response', 'gf-civicrm' ),
		'callback' => function($args) {
			display_webhook_meta_box( $args, "webhook_feed_response" );
		},
		'context'  => 'normal',
		'priority' => 'low',
	];

	return $meta_boxes;
}, 10, 3 );

function display_webhook_meta_box( $args, $meta_key ) {
	// Don't display if the current user is not an admin.
	if (!in_array( 'administrator', (array) wp_get_current_user()->roles )) {
		echo '<p>' . esc_html__( 'You need the administrator role to view this data.', 'gf-civicrm' ) . '</p>';
		return;
	}

	$entry = $args['entry']; // Current entry object.

	// Retrieve the webhook meta data.
	$meta = rgar( $entry, $meta_key );

	if ( ! empty( $meta ) && is_array( $meta ) ) {
		// Display the response code and message.
		echo '<pre style="text-wrap: auto;">';
		print_r( $meta );
		echo '</pre>';
	} else {
		echo '<p>' . esc_html__( 'No data available.', 'gf-civicrm' ) . '</p>';
	}
}
