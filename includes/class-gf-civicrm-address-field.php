<?php

/**
 * Copyright (C) Agileware Pty Ltd
 * Based on original work by WebAware Pty Ltd (email : support@webaware.com.au)
 *
 * This code is based on the original work by WebAware Pty Ltd.
 * The original plugin can be found at: https://gf-address-enhanced.webaware.net.au/
 * Original License: GPLv2 or later
 * Original License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace GFCiviCRM;
use GFAPI;
use GF_Field;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class Address_Field {
	private $field_settings = [];
	private $address_type = null;

	public function __construct() {
		add_filter('gform_field_css_class', [$this, 'applyAddressField'], 10, 3);
	}
	
	public function applyGFCiviCRMAddressField( $form ) {
		// Don't add scripts to admin forms, or pages without forms
		if ( \GFForms::get_page() || empty( $form ) ) {
			return false;
		}

		// Only add to forms with GF Address fields
		$fields = GFAPI::get_fields_by_type( $form, 'address' );

		if ( ! empty( $fields ) ) {
			add_action('wp_print_footer_scripts', [$this, 'addAddressFieldTemplates'], 9);
			add_action('wp_print_footer_scripts', [$this, 'loadCountriesAndStatesData'], 9);
			return true;
		}

		return false;
	}

	/**
	 * Add templates to the page footer
	 */
	public function addAddressFieldTemplates() {
		require_once( GF_CIVICRM_PLUGIN_PATH . 'templates/custom-gf-state-field-templates.php' );
	}

	public function applyAddressField( $classes, $field, $form ) {
		// Mark the address field
		if ( ! empty( $field->type ) && $field->type === 'address' ) {
			$form_id = (int) rgar( $form, 'id' );

			if ( strpos( $classes, 'gf-civicrm-address-field' ) === false ) {
				$classes .= ' gf-civicrm-address-field';
			}

			// also tag this for adding aria-live region and controller markup
			add_filter( "gform_field_content_{$form_id}_{$field->id}", [$this, 'addAriaLiveRegion'], 10, 5 );

			foreach ( $field->inputs as $field_key => $field_meta ) {
				// Get the field placeholder if it exists
				$input       = $field_meta;
				$placeholder = rgar($input, 'placeholder', null);
				$subfield_id = $field_key + 1;

				// Add to field settings
				$this->field_settings['inputs']["input_{$form_id}_{$field->id}_{$subfield_id}"] = [
					'placeholder' => $placeholder,
				];
			}

			$this->address_type = $field->addressType;
		}

		return $classes;
	}

	/**
	 * Refs gf-address-enhanced
	 *
	 * decorate inputs in an Address field with aria-live region / controller
	 * attributes
	 *
	 * @link https://developer.mozilla.org/en-US/docs/Web/Accessibility/ARIA/ARIA_Live_Regions
	 */
	public function addAriaLiveRegion( string $field_content, GF_Field $field, $value, int $entry_id, int $form_id ): string {
		$container_id = "input_{$form_id}_{$field->id}_4_container";
		$states_id    = "input_{$form_id}_{$field->id}_4";
		$country_id   = "input_{$form_id}_{$field->id}_6";

		$field_content = str_replace( "id='$container_id'", "id='$container_id' aria-live='polite'", $field_content );
		$field_content = str_replace( "id='$country_id'", "id='$country_id' aria-controls='$states_id'", $field_content );

		return $field_content;
	}

	/**
	 * Load data for countries and states for script to access
	 */
	public function loadCountriesAndStatesData() {
		// Exit early if there's no address field available, making sure the script isn't loaded unnecessarily
		if ( empty( $this->field_settings ) ) {
			wp_dequeue_script( 'gf_address_enhanced_smart_states' );
			return;
		}

		// Check for cached values
		$cached_countries_data = get_transient( 'gfcv_civicrm_countries' );
		$cached_states_data = get_transient( 'gfcv_civicrm_stateprovinces' );

		$countries = $cached_countries_data ?: [];
		$states_data = $cached_states_data ?: [];

		if ( !$countries || !$states_data || empty( $labels['countries'] ) ) {
			// Reset fields
			$countries = [];
			$states_data = [];

			$profile_name = get_rest_connection_profile();
			$api_params = [
				'select' => [ 'id', 'name', 'iso_code' ],
				'api.StateProvince.get' => [
					'options' => ['limit' => 0, 'sort' => "name ASC"],
				],
				'options' => ['limit' => 0, 'sort' => "name ASC"],
			];
			$api_options = [
				'check_permissions' => 0, // Set check_permissions to false
				'limit' => 0,
			];

			/**
			 * TODO : Filter by the list of available Countries
			 */

			// Get Countries and their States/Provinces from CiviCRM
			$countries = api_wrapper( $profile_name, 'Country', 'get', $api_params, $api_options );

			// Exit early if we didn't get any countries and their states
			if ( empty( $countries ) ) {
				return;
			}

			// Compile the list of states_data and labels
			foreach ( $countries as $country ) {
				$country_name = $country['name'];
				
				$state_province = $country['api.StateProvince.get']['values'] ?? [];
				$country['states'] = $state_province;
				unset($country['api.StateProvince.get']);

				$countries[] = $country;

				foreach ($state_province as $sp) {
					$state_abbreviation                					 = $sp['abbreviation'];
					$state_name                        					 = __( $sp['name'], 'gf-civicrm-formprocessor' );
					$states_data[ $country_name ][ $state_abbreviation ] = [
						$state_abbreviation,
						$state_name,
					];
				}
			}

			set_transient( 'gfcv_civicrm_countries', $countries );
			set_transient( 'gfcv_civicrm_stateprovinces', $states_data );
		}

		// Compile script data
		$script_data = [
			'states' => $states_data,
			'fields' => $this->field_settings,
		];

		// allow integrations to add more data
		$script_data = apply_filters( 'gf_civicrm_address_fields_script_data', $script_data );

		// Load our states data into JS
		wp_add_inline_script( 'gf_civicrm_address_fields', 'const gf_civicrm_address_fields = ' . json_encode( $script_data ), 'before' );
	}

}