<?php
/**
 * Plugin Name: Gravity Forms CiviCRM Integration
 * Plugin URI: https://github.com/agileware/gf-civicrm
 * Description: Extends Gravity Forms to get option lists and defaults from linked CiviCRM Form Processors in a local CiviCRM installation
 * Requires plugins: gravityforms
 * Author: Agileware
 * Author URI: https://agileware.com.au
 * Version: 2.0.0
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

require_once GF_CIVICRM_PLUGIN_PATH . 'includes/gf-civicrm-field-options.php';
require_once GF_CIVICRM_PLUGIN_PATH . 'includes/gf-civicrm-form-processor.php';
require_once GF_CIVICRM_PLUGIN_PATH . 'includes/gf-civicrm-merge-tags.php';
require_once GF_CIVICRM_PLUGIN_PATH . 'includes/gf-civicrm-webhook.php';

// Load wpcmrf integration
add_action( 'gform_loaded', 'GFCiviCRM\gf_civicrm_wpcmrf_bootstrap', 5 );

function gf_civicrm_wpcmrf_bootstrap() {
	require_once( GF_CIVICRM_PLUGIN_PATH . 'includes/class-gf-civicrm-exception.php' );
	require_once( GF_CIVICRM_PLUGIN_PATH . 'includes/gf-civicrm-wpcmrf.php' );
}

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
