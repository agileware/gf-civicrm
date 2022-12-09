<?php

namespace GFCiviCRM;

use Civi\Api4\Contact;
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

	public function init_frontend() {
		parent::init_frontend();

		add_action( 'gform_pre_render', [ $this, 'maybe_authenticate' ] );
	}

	public function maybe_authenticate( $form ) {
		$settings = $this->get_form_settings( $form );

		$auth_checksum = $settings['civicrm_auth_checksum'];

		if ( ! $auth_checksum || ! civicrm_initialize() ) {
			return $form;
		}

		$contact_id = rgget( 'cid' );
		$checksum   = rgget( 'cs' );

		if ( empty( $contact_id ) || empty( $checksum ) ) {
			return $form;
		}

		try {
			if ( \CRM_Core_Session::getLoggedInContactID() ) {
				return $form;
			}

			$session = \CRM_Core_Session::useFakeSession();

			$validator = Contact::validateChecksum( false )
			                    ->setContactId( $contact_id )
			                    ->setChecksum( $checksum )
			                    ->execute()
			                    ->first();

			if ( ! $validator['valid'] ) {
				// Checksum invalid, so don't load the form
				return false;
			}

			// Checksum validated! Pretend we're logged in.
			$session->set( 'userID', $contact_id );
			$session->set( 'authSrc', \CRM_Core_Permission::AUTH_SRC_CHECKSUM );
		} catch ( \CRM_Core_Exception $e ) {
			// ...
		}

		return $form;
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

	public function form_settings_fields( $form ) {
		$fields = [
			[
				// 'label' => esc_html__( 'Allow authentication with checksum', 'gf-civicrm' ),
				'type'        => 'checkbox',
				'name'        => 'civicrm_auth_checksum',
				'description' => esc_html__(
					'Check this option to allow passing a contact id (cid) and checksum (cs) parameter to the form to emulate a CiviCRM contact',
					'gf-civicrm'
				),
				'choices'     => [
					[
						'label' => esc_html__( 'Allow authentication with checksum', 'gf-civicrm' ),
						'name'  => 'civicrm_auth_checksum'
					]
				]
			],
		];

		return [
			[
				'title'  => esc_html__( 'CiviCRM Settings', 'gf-civicrm' ),
				'fields' => &$fields,
			]
		];
	}
}