<?php

namespace GFCiviCRM;

use GFForms, GFAddon;

GFForms::include_addon_framework();

class FieldsAddOn extends GFAddOn {

  protected $_version = GF_CIVICRM_ADDON_VERSION;

  protected $_min_gravityforms_version = '1.9';

  protected $_slug = 'gf-civicrm';

  protected $_path = 'gf-civicrm/gf-civicrm.php';

  protected $_full_path = __FILE__;

  protected $_title = 'Gravity Forms CiviCRM Add-On';

  protected $_short_title = 'CiviCRM Add-On';

  /**
   * @var object $_instance If available, contains an instance of this class.
   */
  private static $_instance = NULL;

  /**
   * Returns an instance of this class, and stores it in the $_instance
   * property.
   *
   * @return object $_instance An instance of this class.
   */
  public static function get_instance() {
    if (self::$_instance == NULL) {
      self::$_instance = new self();
    }

    return self::$_instance;
  }

  /**
   * Include the field early, so it is available when entry exports are being
   * performed.
   */
  public function pre_init() {
    parent::pre_init();

    // Add the CiviCRM Group Contact Select field
    if ($this->is_gravityforms_supported() && class_exists('GF_Field')) {
      require_once('includes/class-gf-field-group-contact-select.php');
	  require_once('includes/class-civicrm-payment-token.php');
    }
  }

  public function init_admin() {
    parent::init_admin();

    // Add the CiviCRM Group Contact Select field settings
    add_action('gform_field_standard_settings', [
      'GF_Field_Group_Contact_Select',
      'field_standard_settings',
    ], 10, 2);

	add_action('gform_field_standard_settings', [
	  'GFCiviCRM\CiviCRM_Payment_Token',
	  'field_standard_settings',
	], 10, 2);
  }

  /**
   * Include gf-civicrm-fields.js when the form contains a 'group_contact_select' type field.
   *
   * @return array
   */
  public function scripts() {
    $scripts = [
      [
        'handle'  => 'gf_civicrm_fields_js',
        'src'     => $this->get_base_url() . '/js/gf-civicrm-fields.js',
        'version' => $this->_version,
        'deps'    => ['jquery'],
        'enqueue' => [
          ['field_types' => ['group_contact_select', 'civicrm_payment_token']],
        ],
      ],

    ];

    return array_merge(parent::scripts(), $scripts);
  }

  /**
   * Include gf-civicrm-fields.css when the form contains a 'group_contact_select' type field.
   *
   * @return array
   */
  public function styles() {
    $styles = [
      [
        'handle'  => 'gf_civicrm_fields_css',
        'src'     => $this->get_base_url() . '/css/gf-civicrm-fields.css',
        'version' => $this->_version,
        'enqueue' => [
          ['field_types' => ['group_contact_select', 'civicrm_payment_token']],
        ],
      ],
    ];

    return array_merge(parent::styles(), $styles);
  }
}