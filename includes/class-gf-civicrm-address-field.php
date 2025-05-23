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

use CRM_Core_Exception;
use GFAPI;
use GF_Field;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class Address_Field {

	const country = '6';
	const state = '4';

	private $field_settings = [];
	private $address_type = null;

	public function __construct() {
		add_filter('gform_field_css_class', [$this, 'applyAddressField'], 10, 3);
		add_filter('gform_pre_validation', [$this, 'unrequireStateProvince']);
	}
	
	public function applyGFCiviCRMAddressField( $form ) {
		// Don't add scripts to admin forms, or pages without forms
		if ( \GFForms::get_page() || empty( $form ) ) {
			return false;
		}

		// Only add to forms with GF Address fields
		$fields = GFAPI::get_fields_by_type( $form, 'address' );

		if ( ! empty( $fields ) ) {
			add_action('wp_print_footer_scripts', [$this, 'loadCountriesAndStatesData'], 9);
			return true;
		}

		return false;
	}

	public function applyAddressField( $classes, $field, $form ) {
		// Mark the address field
		if ( ! empty( $field->type ) && $field->type === 'address' ) {
			$form_id = (int) rgar( $form, 'id' );

			if ( strpos($classes, 'gf-civicrm-address-field') === false ) {
				$classes .= ' gf-civicrm-address-field';
			}

			// also tag this for adding aria-live region and controller markup
			add_filter("gform_field_content_{$form_id}_{$field->id}", [$this, 'addAriaLiveRegion'], 10, 5);

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
		$states_data = [];
		$labels      = [
			'countries' => [],
		];

		// Exit early if there's no address field available, making sure the script isn't loaded unnecessarily
		if ( empty( $this->field_settings ) ) {
			wp_dequeue_script( 'gf_address_enhanced_smart_states' );
			return;
		}

		// Get Countries and their States/Provinces from CiviCRM
		$countries = [];
		if ( $this->address_type == 'us' ) {
			$countries = \Civi\Api4\Country::get( FALSE )
				->addSelect( 'id', 'name', 'iso_code', 'state_province.id', 'state_province.name', 'state_province.abbreviation', 'state_province.country_id' )
				->addJoin( 'StateProvince AS state_province', 'INNER' )
				->addWhere( 'id', '=', 1228 ) // US country_id in CiviCRM
				->addOrderBy( 'name', 'ASC' )
				->addOrderBy( 'state_province.name', 'ASC' )
				->execute();
		} elseif ( $this->address_type == 'canadian' ) {
			$countries = \Civi\Api4\Country::get( FALSE )
				->addSelect( 'id', 'name', 'iso_code', 'state_province.id', 'state_province.name', 'state_province.abbreviation', 'state_province.country_id' )
				->addJoin( 'StateProvince AS state_province', 'INNER' )
				->addWhere( 'id', '=', 1039 ) // Canada country_id in CiviCRM
				->addOrderBy( 'name', 'ASC' )
				->addOrderBy( 'state_province.name', 'ASC' )
				->execute();
		} else {
			// Default to international
			$countries = \Civi\Api4\Country::get( FALSE )
				->addSelect( 'id', 'name', 'iso_code', 'state_province.id', 'state_province.name', 'state_province.abbreviation', 'state_province.country_id' )
				->addJoin( 'StateProvince AS state_province', 'INNER' )
				->addOrderBy( 'name', 'ASC' )
				->addOrderBy( 'state_province.name', 'ASC' )
				->execute();
		}

		// Exit early if we didn't get any countries and their states
		if ( empty( $countries ) ) {
			return;
		}

		// Compile the list of states_data and labels
		foreach ( $countries as $country ) {
			$state_abbreviation                = __( $country['state_province.abbreviation'], 'gf-civicrm-formprocessor' );
			$state_name                        = __( $country['state_province.name'], 'gf-civicrm-formprocessor' );
			$states_data[ $country['name'] ][] = [
				$state_abbreviation,
				$state_name,
			];
			// DEV: Do we need this?
			$labels['countries'][ $country['name'] ][] = __( $country['state_province.name'], 'gf-civicrm-formprocessor' );
		}

		// Compile script data
		$script_data = [
			'states' => $states_data,
			'labels' => $labels,
			'fields' => $this->field_settings,
		];

		// allow integrations to add more data
		$script_data = apply_filters( 'gf_civicrm_address_fields_script_data', $script_data );

		// Load our states data into JS
		wp_add_inline_script( 'gf_civicrm_address_fields', 'const gf_civicrm_address_fields = ' . json_encode( $script_data ), 'before' );
	}

	/**
	 * Override the hidden property of state inputs when there's no possible values,
	 *
	 * @param $form
	 *
	 * @return mixed
	 * @throws \CRM_Core_Exception
	 */
	public function unrequireStateProvince( $form ) {
		$fields = array_filter( $form['fields'], fn( $field ) => $field instanceof \GF_Field_Address );

		/** @var \GF_Field_Address $field */
		foreach($fields as $field) {
			$country = \rgpost( 'input_' . $field->id . '_' . self::country );
			$state = \rgpost( 'input_' . $field->id . '_' . self::state );

			// Only operate when the state field is actually empty.
			if(!empty($state)) {
				continue;
			}

			$state_input = NULL;

			foreach($field->inputs as $index => $input) {
				if ($input['id'] == $field->id . '.' . self::state) {
					$state_input = $index;
					break;
				}

			}


			if($state_input && empty($state)) {
				try {
					$statecount = civicrm_api3( 'StateProvince', 'getcount', [ 'country_id.name' => $country ] );

					if ( ! $statecount ) {
						$field->inputs[ $state_input ]['isHidden'] = TRUE;
					}
				}
				catch(CRM_Core_Exception $e) {

				}
			}
		}

		return $form;
	}

}