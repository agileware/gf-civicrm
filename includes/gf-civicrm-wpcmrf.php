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
function api_wrapper($profile, $entity, $action, $params, $options=[], $api_version = '3', $ignore = false) {
	$profiles = get_profiles();
	if (isset($profiles[$profile])) {
		if (isset($profiles[$profile]['file'])) {
			require_once($profiles[$profile]['file']);
		}
		$result = call_user_func($profiles[$profile]['function'], $profile, $entity, $action, $params, $options, $api_version);
		if (!empty($result['is_error'])) {
			throw new GFCiviCRM_Exception( $result['error_message'], $result['error_code'] );
		}
	} else {
		throw new GFCiviCRM_Exception( __( 'Profile not found', 'gf-civicrm' ) );
	}
	
	return $result;
}

/**
 * Returns a list of possible profiles
 * @return array
 */
function get_profiles() {
	static $profiles = null;
	if (is_array($profiles)) {
	  return $profiles;
	}
  
	$profiles = array();

	// Local CiviCRM connection
	require_once( GF_CIVICRM_PLUGIN_PATH . 'includes/class-local-civicrm.php' );
  	$profiles = LocalCiviCRM::loadProfile( $profiles );

	if ( function_exists('wpcmrf_get_core') ) {
	  $core = \wpcmrf_get_core();
	  $wpcmrf_profiles = $core->getConnectionProfiles();
	  foreach($wpcmrf_profiles as $profile) {
		$profile_name = 'wpcmrf_profile_'.$profile['id'];
		$profiles[$profile_name] = [
		  'title' => $profile['label'],
		  'function' => 'GFCiviCRM\gf_civicrm_wpcmrf_api',
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

	$call = \wpcmrf_api( $entity, $action, $params, $options, $profile_id, $api_version );
	return $call->getReply();
}


function check_civicrm_installation( $profile = null ) {
	if ( is_null( $profile ) ) {
		$form = FieldsAddOn::get_instance()->get_current_form();
		$profile = get_rest_connection_profile( $form );
	}

	$result = api_wrapper( $profile, 'System', 'get', [ 'version' ], [] );

	if ( isset( $result['is_error'] ) && $result['is_error'] == 0 ) {
		return [
			'is_error' => 0,
			'message' => $profile . ' CiviCRM installation is accessible.',
		];
	} else {
		return [
			'is_error' => 1,
			'message' => $result['error_message'] ?? 'Unknown error',
		];
	}
}