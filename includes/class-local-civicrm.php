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
			$contract_errors[] = sprintf( esc_html__( "'%s' is required", 'gf-civicrm' ), '$entity' );
		}
		if ( empty( $action ) ) {
			$contract_errors[] = sprintf( esc_html__( "'%s' is required", 'gf-civicrm' ), '$action' );
		}
		if ( ! is_array( $params ) ) {
			$contract_errors[] = sprintf( esc_html__( "'%s' must be an array", 'gf-civicrm' ), '$params' );
		}

		if( ! empty( $contract_errors ) ){
			throw new GFCiviCRM_Exception( implode( '\r\n', $contract_errors ) );
		}

		if ( ! civi_wp()->initialize() ) {
			return [ 'error' => 'CiviCRM not Initialized', 'is_error' => 1 ];
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
			switch ( (string) $api_version ) {
				case '3':
					// APIv3 only reads check_permissions as a top-level parameter, and defaults to FALSE when
					// called from PHP. Callers pass it in $options, so lift it out.
					if ( isset( $options['check_permissions'] ) ) {
						$params['check_permissions'] = (bool) $options['check_permissions'];
						unset( $options['check_permissions'] );
					}

					if ( ! empty( $options ) ) {
						$params['options'] = $options;
					}

					// CiviCRM writes a backtrace to its log for every failed API3 permission check, e.g. each time a
					// visitor without the Form Processor's permission views a form with CiviCRM defaults. Check
					// first, so a refused call returns an error without reaching the API.
					if ( ! empty( $params['check_permissions'] ) && ! self::isApi3Permitted( $entity, $action, $params ) ) {
						return [
							'is_error'      => 1,
							'error_message' => sprintf( 'Permission denied for %s.%s', $entity, $action ),
							'error_code'    => 'unauthorized',
						];
					}

					$result = civicrm_api3( $entity, $action, $params );
					break;
				case '4':
					$result = civicrm_api4( $entity, $action, $params )->getArrayCopy();
					break;
				default:
					$result = [];
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
	 * Whether the current user passes the permission check CiviCRM will apply to this APIv3 call.
	 *
	 * Mirrors Civi\API\Subscriber\PermissionCheck::onApiAuthorize(), including the alterAPIPermissions hook the
	 * Form Processor extension uses to require each processor's own permission.
	 *
	 * @param string $entity
	 * @param string $action
	 * @param array $params
	 *
	 * @return bool
	 */
	private static function isApi3Permitted( $entity, $action, $params ) {
		// CiviCRM also grants these through ACLs, which this check does not cover. Let the API decide.
		if ( in_array( $entity, [ 'UFGroup', 'UFField', 'ActionSchedule' ], true ) ) {
			return true;
		}

		require_once 'CRM/Core/DAO/permissions.php';
		$permissions = _civicrm_api3_permissions( $entity, $action, $params );

		// The alterAPIPermissions hook may turn the check off.
		if ( empty( $params['check_permissions'] ) ) {
			return true;
		}

		return \CRM_Core_Permission::check( $permissions );
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
				'title'    => esc_html__( 'Local CiviCRM', 'gf-civicrm' ),
				'function' => [ 'GFCiviCRM\LocalCiviCRM', 'api' ],
			];
		}

		return $profiles;
	}

}
