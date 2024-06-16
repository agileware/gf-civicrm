<?php

/**
 * This class is taken straight from cf-civicrm-formprocessor, with minor naming changes.
 * 
 * @author Jaap Jansma <jaap.jansma@civicoop.org>
 * @license AGPL-3.0
 */

 namespace GFCiviCRM;

// All functions are Wordpress-specific.
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class GF_CiviCRM_FormProcessor_LocalCiviCRM {

  public static function api($profile, $entity, $action, $params, $options = []) {
    if (empty($entity) || empty($action) || !is_array($params)) {
      throw new Exception('One of given parameters is empty.');
    }

    if (!civi_wp()->initialize()) {
      return ['error' => 'CiviCRM not Initialized', 'is_error' => '1'];
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
    $wpUserTimezone = get_option('timezone_string');
    if ($wpUserTimezone) {
      date_default_timezone_set($wpUserTimezone);
      \CRM_Core_Config::singleton()->userSystem->setMySQLTimeZone();
    }

    try {
      if (!empty($options)) {
        $params['options'] = $options;
      }
      $result = civicrm_api3($entity, $action, $params);
    } catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage() . '<br><br><pre>' . $e->getTraceAsString() . '</pre>';
      $result = ['error' => $error, 'is_error' => '1'];
    }

    /*
     * Reset the timezone back the original setting.
     */
    if ($wpBaseTimezone) {
      date_default_timezone_set($wpBaseTimezone);
    }

    return $result;
  }

  /**
   * Load local CiviCRM Profile.
   * Only when CiviCRM is installed.
   *
   * @param $profiles
   *
   * @return array
   */
  public static function loadProfile($profiles) {
    if (function_exists('civi_wp') && !function_exists('wpcmrf_get_core')) {
      $profiles['_local_civi_'] = [
        'title' => __('Local CiviCRM'),
        'function' => ['GFCiviCRM\GF_CiviCRM_FormProcessor_LocalCiviCRM', 'api']
      ];
    }
    return $profiles;
  }
}