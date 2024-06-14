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
function gf_civicrm_formprocessor_api_wrapper($profile, $entity, $action, $params, $options=[], $ignore=false) {
	$profiles = gf_civicrm_formprocessor_get_profiles();
	if (isset($profiles[$profile])) {
	  if (isset($profiles[$profile]['file'])) {
		require_once($profiles[$profile]['file']);
	  }
	  $result = call_user_func($profiles[$profile]['function'], $profile, $entity, $action, $params, $options);
	} else {
	  $result = ['error' => 'Profile not found', 'is_error' => 1];
	}
	if (!empty($result['is_error']) && $ignore) {
	  return null;
	}
	return $result;
}

/**
 * Returns a list of possible profiles
 * @return array
 */
function gf_civicrm_formprocessor_get_profiles() {
	static $profiles = null;
	if (is_array($profiles)) {
	  return $profiles;
	}
  
	$profiles = array();

	if (function_exists('wpcmrf_get_core')) {
	  $core = wpcmrf_get_core();
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
function gf_civicrm_get_rest_connection_profile_name( $form = null ) {
	$form_settings = !$form ? FieldsAddOn::get_instance()->get_form_settings( $form ) : null;
	$profile = $form_settings['civicrm_rest_connection'] ?? null;

	if ( is_null( $profile ) || $profile === "default" ) {
		$profile =FieldsAddOn::get_instance()->get_plugin_setting( 'civicrm_rest_connection' );
	}

	return $profile;
}

/**
 * Calls wpcmrf_api() for the given profile (i.e. wpcmrf connection)
 */
function gf_civicrm_wpcmrf_api($profile, $entity, $action, $params, $options = []) {
	$profile_id = substr($profile, 15);
	$options['cache'] ??= '180 minutes';

	$call = wpcmrf_api($entity, $action, $params, $options, $profile_id);
	return $call->getReply();
}