<?php
/**
 * Plugin Name: Gravity Forms CiviCRM Integration
 * Plugin URI: https://bitbucket.org/agileware/gf-civicrm-formprocessor
 * Description: Extends Gravity Forms to get option lists and defaults from linked CiviCRM Form Processors
 * Author: Agileware
 * Author URI: https://agileware.com.au
 * Version: 1.0.1
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

const BEFORE_CHOICES_SETTING = 1350;

/**
 * Replace choices in Gravity Forms with CiviCRM data
 *
 * @param array $form
 * @param array $context
 *
 * @return array
 * @throws \API_Exception
 * @throws \Civi\API\Exception\UnauthorizedException
 */
function do_civicrm_replacement( $form, $context ) {
	static $civi_fp_fields;
	foreach ( $form['fields'] as &$field ) {
		if ( property_exists( $field, 'choices' ) && property_exists( $field, 'civicrmOptionGroup' ) &&
             preg_match( '{(?:^|\s) civicrm (?: __ (?<option_group>\S+) | _fp__ (?<processor>\S*?) __ (?<field> \S*))}x', $field->civicrmOptionGroup, $matches ) ) {
			if ( ! civicrm_initialize() ) {
				break;
			}

			$option_group = $matches['option_group'];

			$options        = [];
			$default_option = null;


			if ( $option_group ) {
				$options = OptionValue::get()
				                      ->addSelect( 'value', 'label', 'is_default' )
				                      ->addWhere( 'option_group_id:name', '=', $option_group )
				                      ->addOrderBy( 'weight', 'ASC' )
				                      ->execute();
			} elseif ( $matches['processor'] && $matches['field'] ) {
				try {
					if ( ! isset( $civi_fp_fields[ $matches['processor'] ] ) ) {
						$civi_fp_fields[ $matches['processor'] ] = civicrm_api3(
							                                           'FormProcessor',
							                                           'getfields',
							                                           [ 'action' => $matches['processor'] ] )['values'] ?? [];
					}

					foreach ( $civi_fp_fields[ $matches['processor'] ][ $matches['field'] ]['options'] ?? [] as $value => $label ) {
						$options[] = [ 'value' => $value, 'label' => $label ];
					}

					// Record the default option from the Form Processor
					$defaults       = civicrm_api3( 'FormProcessorDefaults', $matches['processor'] );
					$default_option = $defaults[ $matches['field'] ] ?? null;

				} catch ( \CiviCRM_API3_Exception $e ) {
					// Couldn't get form processor instance, don't try to set options
				}
			}

			$field->choices = array_map( function ( $option_value ) use ( $default_option ) {
				return [
					'text'       => $option_value['label'],
					'value'      => $option_value['value'],
					'isSelected' => ( ( is_array( $default_option ) && in_array( $option_value['value'], $default_option ) ) || ( $option_value['value'] == $default_option ) || $option_value['is_default'] )
				];
			}, (array) $options );

			if ( ( $context === 'pre_render' ) && ( ! $field->isRequired ) && ( $field->type != 'multiselect' ) ) {
				array_unshift( $field->choices, [
					'text'       => __( '- None -', 'gf-civicrm-formprocessor' ),
					'value'      => null,
					'isSelected' => ! $default_option
				] );
			}

			if ( property_exists( $field, 'inputs' ) ) {
                if($context === 'pre_render') {
	                $i             = 1;
	                $field->inputs = array_map( function ( $choice ) use ( &$i, $field ) {
		                return [
			                'id'    => $field->id . '.' . $i ++,
			                'label' => $choice['text'],
		                ];
	                }, $field->choices );
                } else {
                    $field->inputs = null;
                }
			}

		}

	}

	return $form;
}

add_filter( 'gform_pre_render', function ( $form ) {
	return do_civicrm_replacement( $form, 'pre_render' );
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
	if ( $feed['meta']['requestFormat'] === 'json' ) {
		$json_decoded = [];
		foreach ( $form['fields'] as $field ) {
			if ( property_exists( $field, 'storageType' ) && $field->storageType == 'json' ) {
				$json_decoded[ $field['id'] ] = json_decode( $entry[ $field['id'] ] );
			}
		}
		foreach ( $feed['meta']['fieldValues'] as $field_value ) {
			if ( ( ! empty( $field_value['custom_key'] ) ) && ( $value = $json_decoded[ $field_value['value'] ] ) ) {
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
			$option_groups = OptionGroup::get()
			                            ->addSelect( 'name', 'title' )
			                            ->execute();
			try {
				$form_processors = civicrm_api3( 'FormProcessorInstance', 'get', [ 'sequential' => 1 ] )['values'];

				$form_processors = array_filter( array_map( function ( $processor ) use ( $option_groups ) {
					$mapped = [
						'name'    => $processor['name'],
						'title'   => $processor['title'],
						'options' => []
					];

					foreach ( $processor['inputs'] as $input ) {
						$type = &$input['type']['name'];

						if ( ( $type == 'OptionGroup' ) || ( $type == 'CustomOptionListType' ) || ( $type == 'YesNoOptionList' ) ) {
							$mapped['options'][ $input['name'] ] = $input['title'];
						}
					}

					return ! empty( $mapped['options'] ) ? $mapped : false;
				}, $form_processors ) );
			} catch ( \CiviCRM_API3_Exception $e ) {
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
							echo "<option value=\"civicrm__{$group['name']}\">{$group['title']}</option>";
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
		for ( let field of [ 'select', 'multiselect', 'checkbox', 'radio' ] ) {
			fieldSettings[field] += ', .civicrm_optiongroup_setting'
		}

		jQuery( document ).bind( 'gform_load_field_settings', function ( event, field, form ) {
			jQuery( '#civicrm_optiongroup_selector' ).val( field.civicrmOptionGroup )
		} )

		function SetCiviCRMOptionGroup( { value } ) {
			SetFieldProperty( 'civicrmOptionGroup', value );

			let { cssClass } = GetSelectedField();

			if ( cssClass ) {
				cssClass = cssClass.split( /\s+/ ).filter( entry => !entry.match( /^civicrm(?:_\w+)?__/ ) );
			} else {
				cssClass = [];
			}

			if ( value ) {
				cssClass.push( value );
			}

			cssClass = cssClass.join( ' ' );

			SetFieldProperty( 'cssClass', cssClass );
			document.getElementById( 'field_css_class' ).value = cssClass;
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
function fp_tag_default( $matches ) {
	static $defaults = [];

    $result = '';
	[ , $processor, $field ] = $matches;

	if ( ! civicrm_initialize() ) {
		return $result;
	}

	if ( ! isset( $defaults[ $processor ] ) ) {
		try {
			$defaults[ $processor ] = civicrm_api3( 'FormProcessorDefaults', $processor );
		} catch ( \CiviCRM_API3_Exception $e ) {
			$defaults[ $processor ] = false;
		}
	}

	if ( $defaults[ $processor ] ) {
		$result = array_key_exists( $field, $defaults[ $processor ] ) ? $defaults[ $processor ][ $field ] : '';
	}

	return $result;
}

/**
 * Find or generate API key for the current user.
 */
function get_api_key() {
	if ( ! civicrm_initialize() ) {
		return null;
	}
	$contactID = \CRM_Core_Session::getLoggedInContactID();

	if ( (int) $contactID < 1 ) {
		return null;
	}

    // Get the existing API key if there is one.
	$apiKey = ( Contact::get( false )
	                   ->addSelect( 'api_key' )
	                   ->addWhere( 'id', '=', $contactID )
	                   ->execute() )[0]['api_key'];

	if ( ! $apiKey ) {
        // Otherwise generate and save a key as URL-safe random base64 - 18 bytes = 24 characters
		$apiKey = str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( random_bytes( 18 ) ) );
		Contact::update( false )
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
	if ( ( strpos( $text, '{civicrm_api_key}' ) !== false ) && ( $apiKey = get_api_key() ) ) {
		$text = str_replace( '{civicrm_api_key}', $apiKey, $text );
	}

	return preg_replace_callback(
		'{ {civicrm_fp(?:_default)? \. ([[:alnum:]_]+) \. ([[:alnum:]_]+) } }x',
		'GFCiviCRM\fp_tag_default',
		$text
	);
}

add_filter( 'gform_replace_merge_tags', 'GFCiviCRM\replace_merge_tags', 10, 7 );
