<?php

/**
 * This class is taken straight from cf-civicrm-formprocessor, with minor
 * naming changes.
 *
 * @author Jaap Jansma <jaap.jansma@civicoop.org>
 * @license AGPL-3.0
 */

namespace GFCiviCRM;
use GFCiviCRM_Exception;
use CRM_Core_Exception;

require_once 'class-gf-civicrm-exception.php';

// All functions are Wordpress-specific.
defined( 'ABSPATH' ) or die( 'No direct access' );

class LocalCiviCRM {

	public static function api( $profile, $entity, $action, $params, $options = [], $api_version = '3' ) {
		$contract_errors = [];
		if ( empty( $entity ) ) {
			$contract_errors[] = sprintf( __( "'%s' is required" ), '$entity' );
		}
		if ( empty( $action ) ) {
			$contract_errors[] = sprintf( __( "'%s' is required" ), '$action' );
		}
		if ( ! is_array( $params ) ) {
			$contract_errors = sprintf( __( "'%s' must be an array" ), '$params' );
		}

		if(!empty($contract_errors)){
			throw new GFCiviCRM_Exception( implode( '\r\n', $contract_errors ) );
		}

		if ( ! civi_wp()->initialize() ) {
			return [ 'error' => 'CiviCRM not Initialized', 'is_error' => '1' ];
		}

		/*
		 * Copied from CiviCRM invoke function as there is a problem with timezones
		 * when the local connection is used.
		 *
		 * CRM-12523
		 * WordPress has it's own timezone calculations
		 * CiviCRM relies on the php default timezone which WP
		 * overrides with UTC in wp-settings.php
		 */
		$wpBaseTimezone = date_default_timezone_get();
		$wpUserTimezone = get_option( 'timezone_string' );
		if ( $wpUserTimezone ) {
			date_default_timezone_set( $wpUserTimezone );
			\CRM_Core_Config::singleton()->userSystem->setMySQLTimeZone();
		}

		try {
			switch($api_version) {
				case '3':
					if ( ! empty( $options ) ) {
						$params['options'] = $options;
					}
					$result = civicrm_api3( $entity, $action, $params );
					break;
				case '4':
					/**
					 * DEV NOTE: At time of development, CMRF only supports APIv3 calls. Therefore,
					 * all API requests in this plugin must be APIv3.
					 * 
					 * When this changes in CMRF, we can enable the APIv4 route again.
					 */
					$result = civicrm_api3( $entity, $action, $params );

					// $result = civicrm_api4( $entity, $action, $params )->getArrayCopy();
					break;
			}

			return $result;
		}
		catch ( CRM_Core_Exception $e ) {
			throw new GFCiviCRM_Exception($e->getMessage(), $e->getCode(), $e);
		}
		finally {
			/*
			 * Reset the timezone back the original setting.
			 */
			if ( $wpBaseTimezone ) {
				date_default_timezone_set( $wpBaseTimezone );
			}
		}
	}

	/**
	 * Load local CiviCRM Profile.
	 * Only when CiviCRM is installed.
	 *
	 * @param $profiles
	 *
	 * @return array
	 */
	public static function loadProfile( $profiles ) {
		if ( function_exists( 'civi_wp' ) ) {
			$profiles['_local_civi_'] = [
				'title'    => __( 'Local CiviCRM' ),
				'function' => [ 'GFCiviCRM\LocalCiviCRM', 'api' ],
			];
		}

		return $profiles;
	}

}