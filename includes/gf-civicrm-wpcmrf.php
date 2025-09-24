<?php

/**
 * Copyright (C) Agileware Pty Ltd
 * Based on original work by Jaap Jansma (email : )
 * 
 * This code is based on the original work by Jaap Jansma.
 * The original plugin can be found at: https://github.com/civimrf/cf-civicrm-formprocessor
 * Original License: AGPL-3.0
 * Original License URI: https://www.gnu.org/licenses/agpl-3.0.en.html
 * 
 */

namespace GFCiviCRM;
use GFCiviCRM_Exception;

/**
 * Wrapper function for the CiviCRM api's.
 * We use profiles to connect to different remote CiviCRM.
 *
 * @param $profile
 * @param $entity
 * @param $action
 * @param $params
 * @param array $options
 * @param bool $ignore
 *
 * @return array|mixed|null
 */
function api_wrapper( $profile, $entity, $action, $params, $options=[], $api_version = '3', $ignore = false ) {
	$profiles = get_profiles();

	if ( !isset( $profiles[$profile] ) ) {
		return [
			'is_error' => 1,
			'error_message' => __('Profile not found', 'gf-civicrm'),
			'error_code' => 'profile_not_found',
		];
	}

	if ( isset( $profiles[$profile]['file'] ) ) {
		require_once( $profiles[$profile]['file'] );
	}

	// Perform the API call
	try {
		$result = call_user_func( $profiles[$profile]['function'], $profile, $entity, $action, $params, $options, $api_version );
	} catch ( GFCiviCRM_Exception $e ) {
		$e->logErrorMessage( $e->getErrorMessage(), true );
	}

	if ( !empty( $result['is_error'] ) ) {
		return [
			'is_error' => 1,
			'error_message' => __( $result['error_message'], 'gf-civicrm' ),
			'error_code' => $result['error_code'],
		];
	}

	if ( isset( $result['values'] ) ) {
		return $result['values'];
	}
	
	return $result;
}

/**
 * Returns a list of possible profiles
 * @return array
 */
function get_profiles() {
	static $profiles = null;
	if ( is_array( $profiles ) ) {
	  return $profiles;
	}
  
	$profiles = array();

	// Local CiviCRM connection
	require_once( GF_CIVICRM_PLUGIN_PATH . 'includes/class-local-civicrm.php' );
  	$profiles = LocalCiviCRM::loadProfile( $profiles );

	if ( function_exists('wpcmrf_get_core') ) {
	  $core = \wpcmrf_get_core();
	  $wpcmrf_profiles = $core->getConnectionProfiles();

	  foreach( $wpcmrf_profiles as $profile ) {
		$profile_name = 'wpcmrf_profile_'.$profile['id'];
		$profiles[$profile_name] = [
		  'title' => $profile['label'],
		  'function' => 'GFCiviCRM\gf_civicrm_wpcmrf_api',
		  'url' => $profile['url'],
		  'connector' => $profile['connector'],
		  'site_key' => $profile['site_key'],
		  'api_key' => $profile['api_key'],
		];
	  }
	}
  
	return $profiles;
}

/**
 * Get the CMRF Connection Profile name used for the given form.
 */
function get_rest_connection_profile( $form = null ) {
	// If CMRF is not enabled, return the profile id for the local CiviCRM installation
	if ( !function_exists('wpcmrf_get_core') ) {
		$profiles = get_profiles();
		return array_key_first( $profiles );
	}

	if ( is_null( $form )) {
		$form = FieldsAddOn::get_instance()->get_current_form();
	}

	$form_settings = FieldsAddOn::get_instance()->get_form_settings( $form );
	$profile = $form_settings['civicrm_rest_connection'] ?? null;

	if ( is_null( $profile ) || $profile === "default" ) {
		$profile = FieldsAddOn::get_instance()->get_plugin_setting( 'civicrm_rest_connection' );
	}

	// If still no profile, return the profile id for the local CiviCRM installation
	if ( !$profile ) {
		$profiles = get_profiles();
		return array_key_first( $profiles );
	}

	return $profile;
}

/**
 * Calls wpcmrf_api() for the given profile (i.e. wpcmrf connection)
 */
function gf_civicrm_wpcmrf_api( $profile, $entity, $action, $params, $options = [], $api_version = '3' ) {
	$profile_id = substr( $profile, 15 );

	/**
	 * TODO: Prevent caching of specific error codes.
	 * 
	 * This is likely something that needs to happen in CMRF plugin.
	 * See https://github.com/CiviMRF/CMRF_Abstract_Core/issues/24
	 * 
	 */
	// If options isn't already set, set it to a default value.
	$options['cache'] ??= '15 minutes';

	$core = \wpcmrf_get_core();
	$call = $core->createCall( $profile_id, $entity, $action, $params, $options, NULL, $api_version );
	$core->executeCall( $call );
	return $call->getReply();
}

/**
 * Checks we can connect to CiviCRM. A low-permission API call is sufficient to establish whether or not the 
 * connection is possible. Even if the connection is rejected due to insufficient permissions, if we get a
 * valid response, we can confirm the installation exists.
 */
function check_civicrm_installation( $profile = null ) {
	if ( is_null( $profile ) ) {
		$form = FieldsAddOn::get_instance()->get_current_form();
		$profile = get_rest_connection_profile( $form );
	}

	// Prevent multiple installation checks that slow down processing
	static $installation;

	if ( $installation !== null ) {
		return [
			'is_error' => $installation ? 0 : 1,
			'message'  => $installation
				? "$profile CiviCRM installation is accessible."
				: "$profile CiviCRM installation is not accessible.",
		];
	}

	// Try a low-permission API call (Contact.getfields - only requires access CiviCRM permission)
	$result = api_wrapper( $profile, 'Contact', 'getfields', [], [] );
	$error_message = strtolower( $result['error_message'] ?? '' );
	$has_perm_err  = strpos( $error_message, 'insufficient permission' ) !== false;

	if ( empty( $result['is_error'] ) || $has_perm_err ) {
		// Can establish a connection.
		// If Contact.getfields failed, it may have just been a permission issue, but at least we can confirm the installation exists.
		$installation = true;
		return [
			'is_error' => 0,
			'message'  => "$profile CiviCRM installation is accessible." . ( $has_perm_err ? ' But user has insufficient permissions.' : '' ),
		];
	}

	// Installation probably unreachable
	$installation = false;
	return [
		'is_error' => 1,
		'message'  => "$profile CiviCRM installation could not be accessed. " . ($result['error_message'] ?? 'Unknown error'),
	];
}

/**
 * Checks if the user specified by CiviCRM Site Key and API Key has the necessary permissions 
 * to perform core CiviCRM API requests for this plugin.
 */
function check_civicrm_remote_authentication_connection( $profile_id ) {
	$is_successful = ( $profile_id !== '_local_civi_' );

    if ( $is_successful ) {
        return [ 'success' => true, 'message' => "Connection successful!" ];
    } else {
        return [ 'success' => false, 'message' => "Connection failed." ];
    }
}

add_action( 'wp_ajax_check_civi_connection', 'GFCiviCRM\handle_ajax_connection_preflight_check' );

function handle_ajax_connection_preflight_check() {
    // Verify the security nonce.
    check_ajax_referer( 'gf_civicrm_ajax_nonce', 'security' );

    // Sanitize and retrieve the values.
    $profile_name 	= isset($_POST['profile']) ? sanitize_text_field($_POST['profile']) : '';
    $check_type 	= isset($_POST['check_type']) ? sanitize_key($_POST['check_type']) : '';

    if ( empty( $profile_name ) || empty( $check_type ) ) {
		wp_send_json_error(['message' => 'Missing parameters.']);
    }

	// Dispatch to the correct function based on the check type
	$result = null;
    switch ($check_type) {
        case 'settings':
            $result = api_wrapper($profile_name, 'Setting', 'get', ['return' => 'version']);
            break;
        case 'groups':
            $result = api_wrapper($profile_name, 'Group', 'get', ['limit' => 1]);
            break;
        case 'countries':
            $result = api_wrapper($profile_name, 'Country', 'get', ['limit' => 1]);
            break;
        // Add more 'case' statements here for your other checks...
        default:
            wp_send_json_error(['message' => 'Invalid check type specified.']);
    }

    // Send a JSON response back.
    if ( isset($result['is_error']) && $result['is_error'] === 1 ) {
		wp_send_json_error( ['message' => $result['error_message']] );
    } else {
        wp_send_json_success( $result );
    }
}