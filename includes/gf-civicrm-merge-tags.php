<?php

namespace GFCiviCRM;

use GFAPI;
use GFCommon;
use CRM_Core_Exception;

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

			// Safely return if there was an error
			if ( ( isset( $fields['is_error'] ) && $fields['is_error'] === 1 ) || ( isset( $result['code'] ) && $result['code'] === 'civicrm_rest_api_error' ) ) {
				return $result;
			}

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