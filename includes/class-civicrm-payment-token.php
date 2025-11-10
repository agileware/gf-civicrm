<?php

namespace GFCiviCRM;

use Civi;
use Civi\Api4\{PaymentProcessor, PaymentToken};
use CRM_Core_Exception;
use GF_Field;
use GF_Fields;
use GFCommon;
use RGFormsModel;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class CiviCRM_Payment_Token extends GF_Field {

	/**
	 * @var string $type The field type.
	 */
	public $type = 'civicrm_payment_token';

	/**
	 * @var $civicrm_payment_processor mixed The processor selected for payment tokens
	 */
	public $civicrm_payment_processor;

	/**
	 * Return the field title, for use in the form editor.
	 *
	 * @return string
	 */
	public function get_form_editor_field_title() {
		return esc_attr__( 'CiviCRM Payment Token', 'gf-civicrm' );
	}

	/**
	 * Assign the field button to the Advanced Fields group.
	 *
	 * @return array
	 */
	public function get_form_editor_button() {
		return array(
			'group' => 'advanced_fields',
			'text'  => $this->get_form_editor_field_title(),
		);
	}

	/**
	 * Add Payment Processor field setting to Gravity Forms editor, General Settings
	 *
	 * @param int $position The position the settings should be located at.
	 * @param int $form_id The ID of the form currently being edited.
     *
	 */

	public static function field_standard_settings( int $position, int $form_id ) {

		// BEFORE_CHOICES_SETTING determines the position of the field settings on the form
		if ( $position != BEFORE_CHOICES_SETTING ) {
			return;
		}

		// Check if a CiviCRM installation exists
		if ( check_civicrm_installation()['is_error'] ) {
			return;
		}

		try {
			$profile_name = get_rest_connection_profile();
			
			// Get Payment Processors from CiviCRM
			$api_params = [
				'sequential' => 1,
				'return' => [ 'id', 'title', 'is_test' ],
				'is_active' => 1,
  				'is_test' => ['IS NOT NULL' => 1],
			];
			$api_options = [ 'limit' => 0, 'sort' => "title ASC" ];
			$payment_processors = api_wrapper( $profile_name, 'PaymentProcessor', 'get', $api_params, $api_options );

			if ( $payment_processors['is_error'] || is_null( $payment_processors ) ) {
				// Received error response from API endpoint
				throw new \GFCiviCRM_Exception( $payment_processors['error_message'], $payment_processors['error_code']);
			}

			// This class: payment_token_processor_select_setting must be added to the get_form_editor_field_settings array to be displayed for this field.
			// JS onchange function required to store the selected value when the form is saved. See get_form_editor_inline_script_on_page_render
			?>
            <li class="payment_token_processor_setting field_setting">
                <label for="civicrm_payment_processor">
					<?php esc_html_e( 'Payment Processor', 'gf-civicrm' ); ?>
                </label>
                <select id="civicrm_payment_processor" onchange="SetCiviCRMPaymentProcessorSetting(this.value);">
					<?php echo array_reduce(
                            $payment_processors,
                            fn( $result, $processor ) => $result . sprintf(
                                    '<option value="%1$u">%2$s (ID: %1$u%3$s)</option>',
                                    $processor['id'], $processor['title'], ($processor['is_test'] ? ' : test' : '')
                              ),
                            '' ); ?>
                </select>
            </li>
			<?php
		} catch ( \GFCiviCRM_Exception $e ) {
			$e->logErrorMessage( 'Error getting list of active CiviCRM Payment Processors: ', true );
		}
	}


	public function get_form_editor_inline_script_on_page_render() {
		// Loads the saved value for the field
		$js = sprintf( '
	        ( function( $ ) {
	            const field_type = "%s";
	            console.debug(`Loading for field_type ${field_type}`);
		        $( document ).bind( "gform_load_field_settings", function( event, field ) {
		            if( GetInputType( field ) == field_type ) {
		                console.debug( `Loading ${field.civicrm_payment_processor} as default`, field );
		                $( "#civicrm_payment_processor" ).val( field.civicrm_payment_processor );
		            }
		        } );
		    } )( jQuery ); ',
			$this->type
		);

		// Saves the selected value for the field
		$js .= "function SetCiviCRMPaymentProcessorSetting(value) { SetFieldProperty('civicrm_payment_processor', value); }" . PHP_EOL;

		return $js;
	}

	/**
	 * Returns the field's form editor description.
	 *
	 * @return string
	 * @since 2.5
	 *
	 */
	public function get_form_editor_field_description() {
		return esc_attr__( 'Allows users to select a payment token from those stored in CiviCRM for their contact.', 'gf-civicrm' );
	}

	/**
	 * Returns the field's form editor icon.
	 *
	 * This could be an icon url or a gform-icon class.
	 *
	 * @return string
	 * @since 2.5
	 *
	 */
	public function get_form_editor_field_icon() {
		return 'gform-icon--dropdown';
	}

	function get_form_editor_field_settings() {
		return array(
			'conditional_logic_field_setting',
			'prepopulate_field_setting',
			'error_message_setting',
			// TODO implement the chosen functionality for select fields
			// 'enable_enhanced_ui_setting',
			'label_setting',
			'label_placement_setting',
			'admin_label_setting',
			'size_setting',
			'choices_setting',
			'rules_setting',
			'placeholder_setting',
			'default_value_setting',
			'visibility_setting',
			// Disabled this option as not "possible" to have duplicate Contact IDs in a CiviCRM Group
			// 'duplicate_setting',
			'description_setting',
			'css_class_setting',
			'autocomplete_setting',
			'payment_token_processor_setting',
		);
	}

	public function is_conditional_logic_supported() {
		return true;
	}

	public function get_field_input( $form, $value = '', $entry = null ) {
		$form_id         = absint( $form['id'] );
		$is_entry_detail = $this->is_entry_detail();
		$is_form_editor  = $this->is_form_editor();

		$id       = $this->id;
		$field_id = $is_entry_detail || $is_form_editor || $form_id == 0 ? "input_$id" : 'input_' . $form_id . "_$id";

		$size                   = $this->size;
		$class_suffix           = $is_entry_detail ? '_admin' : '';
		$class                  = $size . $class_suffix;
		$css_class              = trim( esc_attr( $class ) . ' gfield_select' );
		$tabindex               = $this->get_tabindex();
		$disabled_text          = $is_form_editor ? 'disabled="disabled"' : '';
		$required_attribute     = $this->isRequired ? 'aria-required="true"' : '';
		$invalid_attribute      = $this->failed_validation ? 'aria-invalid="true"' : 'aria-invalid="false"';
		$describedby_attribute  = $this->get_aria_describedby();
		$autocomplete_attribute = $this->enableAutocomplete ? $this->get_field_autocomplete_attribute() : '';

		return sprintf( '<div class="ginput_container ginput_container_select"><select name="input_%d" id="%s" class="%s" %s %s %s %s %s %s>%s</select></div>',
            $id, $field_id, $css_class,
            $tabindex, $describedby_attribute, $disabled_text, $required_attribute, $invalid_attribute, $autocomplete_attribute,
            $this->get_choices( $value )
        );

	}

	public function get_choices( $value ) {
		return $this->payment_token_options( $this, $value );
	}

	public function get_value_entry_list( $value, $entry, $field_id, $columns, $form ) {
		$return = esc_html( $value );

		return GFCommon::selection_display( $return, $this, $entry['currency'] );
	}

	/*
	 * Return the list of contacts for the selected group
	 */

	public function payment_token_options( $field, $value = '', $support_placeholders = true ) {
		// Define empty value to return
		$empty_option = '<option value="">' . esc_html__( 'Not Available', 'gf-civicrm' ) . '</option>';

		// If no group has been selected, then return empty
		if ( ! isset( $this->civicrm_payment_processor ) ) {
			return $empty_option;
		}

		// No CiviCRM, then return empty
		if ( check_civicrm_installation()['is_error'] ) {
			return $empty_option;
		}

		try {
			$profile_name = get_rest_connection_profile();
			$profiles = get_profiles();
			$profile = $profiles[ $profile_name ] ?? [];

			$contact_id = validateChecksumFromURL();

			// TODO: Recent CMRF Versions have remove the local CiviCRM connection profile option. This name is one we've
			// added ourselves in GF CiviCRM.
			if ( $profile_name !== "_local_civi_" && isset($profile['connector']) ) {
				/**
				 * DEV NOTE: This is a workaround.
				 * 
				 * It utilises a SearchKit set up as an API endpoint, so we can get PaymentToken values for remote installations,
				 * circumventing permissions. This is NOT final. In future we are looking to implement a more robust, cleaner solution.
				 * 
				 * 
				 */
				$api_params['checkPermissions'] = false;
				$api_params['display'] = 'API';
				$api_params['savedSearch'] = 'API_Credit_card_tokens_with_associated_payment_data';
				$api_params['afform'] = 'afsearchAPIForm';
				$api_params['filters'] = [
					"payment_processor_id" => $field['civicrm_payment_processor'], 
					"contact_id" => $contact_id
				];

				$payment_tokens = api_wrapper($profile_name, 'SearchDisplay', 'run', $api_params, [], 4);
				$payment_tokens = array_column($payment_tokens, 'data');

				$keymap = [
					'PaymentToken_ContributionRecur_payment_token_id_01_ContributionRecur_Contribution_contribution_recur_id_01.contribution_status_id:label' 		=> 'label',
					'PaymentToken_ContributionRecur_payment_token_id_01.amount'                       																=> 'amount',
					'PaymentToken_ContributionRecur_payment_token_id_01.currency:label'																				=> 'currency',
					'PaymentToken_ContributionRecur_payment_token_id_01.frequency_interval'           																=> 'interval',
					'PaymentToken_ContributionRecur_payment_token_id_01.frequency_unit:label'         																=> 'unit',
					'PaymentToken_ContributionRecur_payment_token_id_01.financial_type_id:label'      																=> 'financial_type',
					'PaymentToken_ContributionRecur_payment_token_id_01.id'                           																=> 'contribution_recur_id',
					'PaymentToken_ContributionRecur_payment_token_id_01_ContributionRecur_Membership_contribution_recur_id_01.membership_type_id:label'             => 'membership_type',
					'PaymentToken_ContributionRecur_payment_token_id_01_ContributionRecur_Contribution_contribution_recur_id_01.source'                             => 'source',
					'PaymentToken_ContributionRecur_payment_token_id_01_ContributionRecur_Contribution_contribution_recur_id_01.contribution_page_id:label'         => 'contribution_page',
				];
			} else {
				// Fetch the payment tokens for the selector processor
				// No apparent permissions for payment token, permissions check seems to fail without admin
				// contact_id = user_contact_id should be sufficient security.
				$api_params = [
					'select' => [
						'id', 'masked_account_number', 'expiry_date', 'token',
						'contribution_recur.contribution_status_id:label',
						'contribution_recur.amount',
						'contribution_recur.currency:abbr',
						'contribution_recur.frequency_unit:label',
						'contribution_recur.frequency_interval',
						'contribution_recur.financial_type_id:label',
						'contribution_recur.id',
						'membership.membership_type_id:label',
						'contribution.source',
						'contribution.contribution_page_id:label'
					],
					'join' => [
						['ContributionRecur AS contribution_recur', 'LEFT', ['contribution_recur.payment_token_id', '=', 'id']],
						['Membership AS membership', 'LEFT', ['membership.contribution_recur_id', '=', 'contribution_recur.id']],
						['Contribution AS contribution', 'LEFT', ['contribution.contribution_recur_id', '=', 'contribution_recur.id']],
					],
					'where' => [
						['contribution_recur.contribution_status_id:name', 'IN', [ 'In Progress', 'Pending', 'Failing', 'Processing', ]],
						['payment_processor_id', '=', $field['civicrm_payment_processor']],
					],
					'orderBy' => [
						'expiry_date' => 'DESC',
						'contribution_recur.id' => 'DESC',
					],
					'checkPermissions' => FALSE,
				];
				
				
				if ( $contact_id = validateChecksumFromURL() ) {
					// cid and cs provided in URL. Could be remote, or non-logged in
					$api_params['where'][] = ['contact_id', '=', $contact_id];
				} else if ( method_exists( 'CRM_Core_Session', 'getLoggedInContactID' ) && !empty( \CRM_Core_Session::getLoggedInContactID() ) ) {
					// Assume Local connection, get logged in contact. Default to auto-select current user
					$api_params['where'][] = ['contact_id', '=', 'user_contact_id'];
				} else {
					// Couldn't establish a local connection or retrieve a contact ID from a local installation, 
					// and no valid cid and cs provided.
					return $empty_option;
				}
				$payment_tokens = api_wrapper($profile_name, 'PaymentToken', 'get', $api_params, [], 4);

				$keymap = [
					'contribution_recur.contribution_status_id:label' => 'label',
					'contribution_recur.amount'                       => 'amount',
					'contribution_recur.frequency_interval'           => 'interval',
					'contribution_recur.frequency_unit:label'         => 'unit',
					'contribution_recur.financial_type_id:label'      => 'financial_type',
					'contribution_recur.id'                           => 'contribution_recur_id',
					'membership.membership_type_id:label'             => 'membership_type',
					'contribution.source'                             => 'source',
					'contribution.contribution_page_id:label'         => 'contribution_page',
				];
			}

			if ( empty($payment_tokens) ) {
				// TODO Add frontend messaging that no payment methods were found
				return $empty_option;
			}

			// Rekey the results from the keymap
			$payment_tokens = array_map( function( $entry ) use ( $keymap ) {
				$new_entry = [];
				foreach ( $entry as $old_key => $value ) {
					if ( array_key_exists( $old_key, $keymap ) ) {
						$new_entry[$keymap[$old_key]] = $value;
					} else {
						$new_entry[$old_key] = $value;
					}
				}
				return $new_entry;
			}, $payment_tokens );

			$payment_tokens_unique = array_column( $payment_tokens, NULL, 'id' );

			foreach( array_filter( $payment_tokens, fn($t) => !empty($t['membership_type']) ) as $payment_token ) {
				$payment_tokens_unique[$payment_token['id']]['membership_type'] ??= $payment_token['membership_type'];
			}

            $field->choices = [];

            if( !( $this->isRequired ?? false ) ) {
                $field->choices[] = [
                    'text' => esc_html__('Add new card', 'gf-civicrm'),
                    'value' => '',
                    'isSelected' => false,
                ];
            }

			foreach ( $payment_tokens_unique as $payment_token ) {
				$for_string = ( $payment_token['membership_type'] ? 'Membership: ' . $payment_token['membership_type'] : NULL );
				$for_string ??= ( $payment_token['source']
								  ?? $payment_token['contribution_page']
								  ?? $payment_token['financial_type']
								  ?? 'unknown' );
				$for_string = strtr($for_string, ["\r" => '', "\n" => ' ']);
				$field->choices[] = [
					'text' => sprintf( '*%1$s (Expires %2$s) - $%3$.2f every %4$u %5$s for %6$s',
						substr( $payment_token['masked_account_number'], - 4 ),
						date_create_immutable( $payment_token['expiry_date'] )->format( 'm/y' ),
						$payment_token['amount'],
						$payment_token['interval'],
						$payment_token['unit'],
						$for_string
					),
					'value'      => (string) $payment_token['id'],
					'isSelected' => false,
				];
			}

            if( empty( $field->choices )) {
				// TODO Add frontend messaging that no payment methods were found
                return $empty_option;
            }
		} catch ( \GFCiviCRM_Exception $e ) {
			$e->logErrorMessage( 'Error retrieving Payment Tokens: ', true );
			// TODO: Add some kind of error messaging on the frontend

			return $empty_option;
		}

		// Return the select options using the Gravity Forms common select function
		return GFCommon::get_select_choices( $field, $value, $support_placeholders );
	}

	/**
	 * Validates the field inputs.
	 *
	 * @access public
	 *
	 * @used-by GFFormDisplay::validate()
	 *
	 * @param array|string $value The field value or values to validate.
	 * @param array $form The Form Object.
	 *
	 * @return void
	 */
	public function validate( $value, $form ) {
		// If the field is required
		if ( $this->isRequired ) {
			// Then check that the value selected is a number (assuming this is a contact id)
			if ( ! is_numeric( $value ) ) {
				$this->failed_validation  = true;
				$this->validation_message = esc_attr__( 'This field is required.', 'gf-civicrm' );
			}
		}
	}

	/**
	 * Gets merge tag values.
	 *
	 * @param array|string $value The value of the input.
	 * @param string $input_id The input ID to use.
	 * @param array $entry The Entry Object.
	 * @param array $form The Form Object
	 * @param string $modifier The modifier passed.
	 * @param array|string $raw_value The raw value of the input.
	 * @param bool $url_encode If the result should be URL encoded.
	 * @param bool $esc_html If the HTML should be escaped.
	 * @param string $format The format that the value should be.
	 * @param bool $nl2br If the nl2br function should be used.
	 *
	 * @return string The processed merge tag.
	 * @since  Unknown
	 * @access public
	 *
	 * @uses GFCommon::to_money()
	 * @uses GFCommon::format_post_category()
	 * @uses GFFormsModel::is_field_hidden()
	 * @uses GFFormsModel::get_choice_text()
	 * @uses GFCommon::format_variable_value()
	 * @uses GFCommon::implode_non_blank()
	 *
	 */
	public function get_value_merge_tag( $value, $input_id, $entry, $form, $modifier, $raw_value, $url_encode, $esc_html, $format, $nl2br ) {
		$modifiers       = $this->get_modifiers();
		$use_value       = in_array( 'value', $modifiers );

		if ( is_array( $raw_value ) && (string) intval( $input_id ) != $input_id ) {
			$items = array( $input_id => $value ); // Float input Ids. (i.e. 4.1 ). Used when targeting specific checkbox items.
		} elseif ( is_array( $raw_value ) ) {
			$items = $raw_value;
		} else {
			$items = array( $input_id => $raw_value );
		}

		$ary = array();

		foreach ( $items as $input_id => $item ) {
			if ( $use_value ) {
				[ $val, ] = rgexplode( '|', $item, 2 );
			}
            elseif ( $this->type == 'post_category' ) {
				$use_id     = strtolower( $modifier ) == 'id';
				$item_value = GFCommon::format_post_category( $item, $use_id );

				$val = RGFormsModel::is_field_hidden( $form, $this, array(), $entry ) ? '' : $item_value;
			} else {
				$val = RGFormsModel::is_field_hidden( $form, $this, array(), $entry ) ? '' : RGFormsModel::get_choice_text( $this, $raw_value, $input_id );
			}

			$ary[] = GFCommon::format_variable_value( $val, $url_encode, $esc_html, $format );
		}

		return GFCommon::implode_non_blank( ', ', $ary );
	}

	public function get_value_entry_detail( $value, $currency = '', $use_text = false, $format = 'html', $media = 'screen' ) {
		$return = esc_html( $value );

		return GFCommon::selection_display( $return, $this, $currency, $use_text );
	}

	public function get_value_export( $entry, $input_id = '', $use_text = false, $is_csv = false ) {
		if ( empty( $input_id ) ) {
			$input_id = $this->id;
		}

		$value = rgar( $entry, $input_id );

		return $is_csv ? $value : GFCommon::selection_display( $value, $this, rgar( $entry, 'currency' ), $use_text );
	}

	/**
	 * Strips all tags from the input value.
	 *
	 * @param string $value The field value to be processed.
	 * @param int $form_id The ID of the form currently being processed.
	 *
	 * @return string
	 */
	public function sanitize_entry_value( $value, $form_id ) {
		return wp_strip_all_tags( $value );
	}

	/**
	 * Returns the filter operators for the current field.
	 *
	 * @return array
	 * @since 2.4
	 *
	 */
	public function get_filter_operators() {
		return [ 'is', 'isnot' ];
	}

}

GF_Fields::register( new CiviCRM_Payment_Token() );
