<?php

use GFAPI;
use GFForms;
use GFFormsModel;
use GF_Field;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class GF_FIELD_CIVICRM_ADDRESS_FIELD {
	private $field_settings = [];

	public function __construct() {
		add_filter('gform_field_css_class', [$this, 'applyAddressFieldCustomisation'], 10, 3);
	}
	
	public function applyGFCiviCRMAddressField( $form ) {
		// Don't add scripts to admin forms, or pages without forms
		if ( \GFForms::get_page() || empty($form) ) {
			return false;
		}

		// Only add to forms with GF Address fields
		$fields = GFAPI::get_fields_by_type($form, 'address');

		if ( !empty( $fields ) ) {
			add_action('wp_print_footer_scripts', [$this, 'addAddressFieldTemplates'], 9);
			add_action('wp_print_footer_scripts', [$this, 'loadStatesData'], 9);
			return true;
		}

		return false;
	}

	/**
	 * Add templates to the page footer
	 */
	public function addAddressFieldTemplates() {
		require_once( GF_CIVICRM_PLUGIN_PATH . 'templates/custom-gf-state-field-templates.php');
	}

	public function applyAddressFieldCustomisation( $classes, $field, $form ) {
		// Mark the address field
		if ( !empty($field->type) && $field->type === 'address' ) {
			$form_id = (int) rgar($form, 'id');

			if ( strpos($classes, 'gf-civicrm-address-field') === false ) {
				$classes .= ' gf-civicrm-address-field';
			}

			// also tag this for adding aria-live region and controller markup
			//add_filter("gform_field_content_{$form_id}_{$field->id}", [$this, 'addAriaLiveRegion'], 10, 5);

			foreach ($field->inputs as $field_key => $field_meta) {
				// Get the field placeholder if it exists
				$input = $field_meta;
				$placeholder = rgar($input, 'placeholder', null);
				$subfield_id = $field_key + 1;

				// add to field settings map
				$this->field_settings['inputs']["input_{$form_id}_{$field->id}_{$subfield_id}"] = [
					'placeholder'	=> $placeholder,
				];
			}

			//error_log(print_r($this->field_settings, true));
			
		}

		return $classes;
	}

	/**
	 * load data for states for script to access
	 * 
	 * DEV: May need to go in a separate class
	 */
	public function loadStatesData() {
		$states_data = [];
		$labels = [
			'countries'	=> [],
		];

		// Exit early if there's no address field available, making sure the script isn't loaded unnecessarily
		if ( empty($this->field_settings) ) {
			wp_dequeue_script('gf_address_enhanced_smart_states');
			return;
		}

		// TODO: Get Countries from CiviCRM
		$countries = array();

		// TODO: Check if each Country has a list of states in CiviCRM
		foreach ($countries as $name => $code) {
			/*$country = Country::getCountry($code, $name);
			if ($country->hasSupportedStates()) {
				$states_data[$country->getName()] = $country->getStatesForJSON();

				// maybe add to list of state subfield labels
				if ($country->statesLabel) {
					if (!isset($labels['countries'][$country->statesLabel])) {
						$labels['countries'][$country->statesLabel] = [];
					}
					$labels['countries'][$country->statesLabel][] = $country->getName();
				}
			}*/
		}

		// TODO: Add each state name to a list for each country

		// TODO: Compile script data

		// TODO: Send script data to JS via wp_add_inline_script()

		

		/*$script_data = [
			'states'	=> $states_data,
			'labels'	=> $labels,
			//'fields'	=> $this->field_settings,
		];

		// allow integrations to add more data
		$script_data = apply_filters('gf_address_enhanced_smart_states_script_data', $script_data);

		// if there's no Address fields, then we don't need to load the script or localise it
		if (empty($script_data['fields'])) {
			wp_dequeue_script('gf_address_enhanced_smart_states');
		}
		else {
			wp_localize_script('gf_address_enhanced_smart_states', 'gf_address_enhanced_smart_states', $script_data);
		}*/
	}

}