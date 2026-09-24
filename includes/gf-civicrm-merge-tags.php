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
				'check_permissions' => 1, // Enforce the Form Processor's permission for front-end visitors
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
				// Secure direct usage of $_GET via unslashing and sanitization 
				if ( ! empty( $_GET[ $value['name'] ] ) ) {
					$api_params[ $value['name'] ] = sanitize_text_field( wp_unslash( $_GET[ $value['name'] ] ) );
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
 * Get a CiviCRM credential ('site_key' or 'api_key') from the selected connection profile, or from the
 * plugin settings.
 *
 * @param string $name
 *
 * @return string
 */
function get_civicrm_credential( $name ) {
	$profile_name  = get_rest_connection_profile();
	$profiles      = get_profiles();
	$plugin_active = is_plugin_active( 'connector-civicrm-mcrestface/wpcmrf.php' );

	if ( $plugin_active && isset( $profiles[ $profile_name ][ $name ] ) ) {
		return (string) $profiles[ $profile_name ][ $name ];
	}

	return (string) FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_' . $name );
}

/**
 * Replace the {gf_civicrm_site_key} and {gf_civicrm_api_key} merge tags with the CiviCRM credentials.
 *
 * Only used while a Gravity Forms Webhooks request is being built, so the credentials cannot be output in
 * confirmations, notifications, field values or anywhere else merge tags are processed.
 *
 * @param mixed $text
 * @param bool  $json_escape Escape the credentials for use inside a JSON string.
 *
 * @return mixed
 */
function replace_key_merge_tags( $text, $json_escape = false ) {
	if ( ! is_string( $text ) ) {
		return $text;
	}

	$tags = [
		'{gf_civicrm_site_key}' => 'site_key',
		'{gf_civicrm_api_key}'  => 'api_key',
	];

	foreach ( $tags as $tag => $name ) {
		if ( strpos( $text, $tag ) === false ) {
			continue;
		}

		$value = get_civicrm_credential( $name );
		if ( $json_escape ) {
			$value = substr( json_encode( $value ), 1, -1 );
		}

		$text = str_replace( $tag, $value, $text );
	}

	return $text;
}

/**
 * Replace the key merge tags in the webhook request URL. Runs after the request URL is saved to the entry
 * (priority 10), so the saved URL keeps the merge tags.
 */
add_filter( 'gform_webhooks_request_url', function ( $request_url ) {
	return replace_key_merge_tags( $request_url );
}, 20 );

/**
 * Replace the key merge tags in the webhook request headers and body. Runs after the request arguments are
 * saved to the entry (priority 10), so the saved arguments keep the merge tags.
 */
add_filter( 'gform_webhooks_request_args', function ( $request_args ) {
	if ( ! empty( $request_args['headers'] ) && is_array( $request_args['headers'] ) ) {
		foreach ( $request_args['headers'] as $name => $value ) {
			$request_args['headers'][ $name ] = replace_key_merge_tags( $value );
		}
	}

	if ( ! empty( $request_args['body'] ) ) {
		if ( is_array( $request_args['body'] ) ) {
			array_walk_recursive( $request_args['body'], function ( &$value ) {
				$value = replace_key_merge_tags( $value );
			} );
		} elseif ( is_string( $request_args['body'] ) ) {
			// JSON request bodies are sent as a string
			$is_json = json_decode( $request_args['body'] ) !== null;
			$request_args['body'] = replace_key_merge_tags( $request_args['body'], $is_json );
		}
	}

	return $request_args;
}, 20 );

/**
 * Find and replace {civicrm_fp.*} and {gf_civicrm_rest_url} merge tags.
 *
 * {gf_civicrm_site_key} and {gf_civicrm_api_key} are deliberately not replaced here; see replace_key_merge_tags().
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
	$needs_rest_url  = strpos( $text, $gf_civicrm_rest_url_merge_tag ) !== false;

	if ( $needs_rest_url ) {
		$profile_name = get_rest_connection_profile();
		$profiles     = get_profiles();
		$plugin_active = is_plugin_active( 'connector-civicrm-mcrestface/wpcmrf.php' );

		if ( $plugin_active && isset( $profiles[ $profile_name ] ) ) {
			$profile = $profiles[ $profile_name ];
		} else {
			$profile = null;
		}

		$gf_civicrm_rest_url = $profile && isset( $profile['url'] ) ? $profile['url'] : GFCommon::format_variable_value( rest_url(), $url_encode, $esc_html, $format, $nl2br );
		$text = str_replace( $gf_civicrm_rest_url_merge_tag, $gf_civicrm_rest_url, $text );
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