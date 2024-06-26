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

const BEFORE_CHOICES_SETTING = 1350;

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

			$options        = [];
			$default_option = NULL;

			if ( $option_group ) {
				$options = OptionValue::get( FALSE )
				                      ->addSelect( 'value', 'label', 'is_default' )
				                      ->addWhere( 'option_group_id:name', '=', $option_group )
				                      ->addWhere( 'is_active', '=', TRUE )
				                      ->addOrderBy( 'weight', 'ASC' )
				                      ->execute();
			} elseif ( $processor && $field_name ) {
				try {
					if ( ! isset( $civi_fp_fields[ $processor ] ) ) {
						$civi_fp_fields[ $processor ] = civicrm_api3( 'FormProcessor', 'getfields', [ 'action' => $processor ] )['values'] ?? [];
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

	return preg_replace_callback(
		'{ {civicrm_fp(?:_default)? \. ([[:alnum:]_]+) \. ([[:alnum:]_]+) } }x',
		'GFCiviCRM\fp_tag_default',
		$text
	);
}

add_filter( 'gform_custom_merge_tags', 'GFCiviCRM\compose_merge_tags', 10, 1 );

add_filter( 'gform_replace_merge_tags', 'GFCiviCRM\replace_merge_tags', 10, 7 );

define( 'GF_CIVICRM_FIELDS_ADDON_VERSION', get_file_data( __FILE__, [ 'Version' => 'Version' ] )['Version'] );

add_filter( 'admin_enqueue_scripts', fn() => wp_enqueue_script( 'gf-civicrm-merge-tags', plugin_dir_url(__FILE__) . 'js/gf-civicrm-merge-tags.js', [ 'wp-i18n' ], GF_CIVICRM_FIELDS_ADDON_VERSION ));

add_action( 'gform_loaded', 'GFCiviCRM\fields_addon_bootstrap', 5 );

function fields_addon_bootstrap() {

	if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
		return;
	}

	require_once( 'class-gf-civicrm-fields.php' );

	GFAddOn::register( 'GFCiviCRM\FieldsAddOn' );
}

// Ensure that other WordPress plugins have not lowered the curl timeout which impacts Gravity Forms webhook requests
function webhooks_request_args( $request_args, $feed, $entry, $form ) {
    // Set timeout to 10 seconds
	$request_args['timeout'] = 10000;

	return $request_args;
}

add_filter( 'gform_webhooks_request_args', 'GFCiviCRM\webhooks_request_args', 10, 4 );
