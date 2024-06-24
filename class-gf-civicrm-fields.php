<?php

namespace GFCiviCRM;

use Civi\Api4\Contact;
use GFForms, GFAddon, GFAPI;

GFForms::include_addon_framework();

class FieldsAddOn extends GFAddOn {

  protected $_version = GF_CIVICRM_FIELDS_ADDON_VERSION;

  protected $_min_gravityforms_version = '1.9';

  protected $_slug = 'gf-civicrm';

  protected $_path = 'gf-civicrm/gf-civicrm.php';

  protected $_full_path = __FILE__;

  protected $_title = 'Gravity Forms CiviCRM Add-On';

  protected $_short_title = 'CiviCRM';

  private $gf_civicrm_address_field;

  /**
   * @var \GFCiviCRM\FieldsAddOn $_instance If available, contains an instance of this class.
   */
  private static $_instance = NULL;

  /**
   * Returns an instance of this class, and stores it in the $_instance
   * property.
   *
   * @return \GFCiviCRM\FieldsAddOn $_instance An instance of this class.
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

		add_action( 'gform_pre_render', [ $this, 'maybe_authenticate' ], 9, 1 );
	}

	public function init() {
		parent::init();

		if ( $this->is_gravityforms_supported() && class_exists('GF_Field') ) {
			require_once( 'includes/class-gf-civicrm-address-field.php' );
			$this->gf_civicrm_address_field = new \GF_CiviCRM_Address_Field();
		}

		add_filter( 'gform_is_delayed_pre_process_feed', [$this, 'switchIsDelayed'], 10, 4 );
		
	}

	protected function hasPaymentAddon( $form, $entry ) {
		static $payment_feed_slugs = [];
		$feeds = GFAPI::get_feeds( NULL, $form['id'] );

		if ( empty($payment_feed_slugs) ) {
			foreach(GFAddon::get_registered_addons( TRUE ) as $feed_instance) {
				if ( $feed_instance instanceof \GFPaymentAddOn ) {
					$payment_feed_slugs[ $feed_instance->get_slug() ] = $feed_instance;
				}
			}
		}

		foreach ( $feeds as $feed ) {
			if ( array_key_exists( $feed['addon_slug'], $payment_feed_slugs ) &&
			     $payment_feed_slugs[ $feed['addon_slug'] ]->is_feed_condition_met( $feed, $form, $entry ) ) {
				return true;
			}
		}

		return false;
	}

	public function switchIsDelayed($is_delayed, $form, $entry, $addon_slug) {
		if ( !$is_delayed &&
		     ( $addon_slug === 'gravityformswebhooks' ) &&
		     $this->hasPaymentAddOn( $form, $entry ) ) {
			$is_delayed = true;
		}
		return $is_delayed;
	}

	public function maybe_authenticate( $form ) {
		$settings = $this->get_form_settings( $form ) ?: [];

		$auth_checksum = $settings['civicrm_auth_checksum'] ?? false;

		if ( ! $auth_checksum || check_civicrm_installation()['is_error'] ) {
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
          ['field_types' => ['group_contact_select', 'civicrm_payment_token', 'address']],
        ],
      ],
	  [
		'handle'  	=> 'gf_civicrm_address_fields',
        'src'		=> $this->get_base_url() . '/js/gf-civicrm-address-fields.js',
        'version'	=> $this->_version,
        'deps'		=> ['jquery', 'wp-util'],
		'in_footer'	=> true,
        'enqueue' 	=> [
		  [$this->gf_civicrm_address_field, 'applyGFCiviCRMAddressField']
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
			// CiviCRM McRestFace
			[
				'label' => esc_html__( 'CiviCRM REST Connection Profile', 'gf-civicrm' ),
				'type'        => 'select',
				'name'        => 'civicrm_rest_connection',
				'description' => esc_html__(
					'Select which CMRF connection profile to use for this form.',
					'gf-civicrm'
				),
				'choices'     => $this->get_cmrf_profile_options()
			],
		];

		return [
			[
				'title'  => esc_html__( 'CiviCRM Settings', 'gf-civicrm' ),
				'fields' => &$fields,
			],
		];
	}

	public function plugin_settings_fields() {
		return [ 
			[
				'title'       => esc_html__( 'CiviCRM Settings', 'gf-civicrm' ),
				'description' => esc_html__( 'Global settings for CiviCRM add-on', 'gf-civicrm' ),
				'fields'      => [ 
					[
						'type'          => 'checkbox',
						'name'          => 'gf_civicrm_flags',
						'default_value' => [ 'civicrm_multi_json' ],
						'choices' => [
							[
								'label'   => esc_html__( 'Use JSON encoding for Checkbox and Multiselect values in webhooks (not recommended)', 'gf_civicrm' ),
								'name'    => 'civicrm_multi_json',
							],
						],
					],
					// CiviCRM McRestFace or Local
					[
						'label' => esc_html__( 'CiviCRM REST Connection Profile', 'gf-civicrm' ),
						'type'          => 'select',
						'name'          => 'civicrm_rest_connection',
						'choices' => $this->get_cmrf_profile_options( true ),
					] 
				],
			]
		];
	}

	/**
	 * Build choices for selectiing CMRF connection profiles on a global or per-form basis.
	 */
	public function get_cmrf_profile_options( $is_global = false ) {
		$options = [];

		// Null or Default options
		if ( $is_global ) {
			$options[] = [
				'label' => esc_html__( "None", 'gf_civicrm' ),
				'value' => ""
			];
		} else {
			$options[] = [
				'label' => esc_html__( "Default", 'gf_civicrm' ),
				'value' => "default"
			];
		}

		$profiles = formprocessor_get_profiles();
		foreach ($profiles as $profile_id => $profile) {
			$options[] = [
				'label' => esc_html__( $profile['title'], 'gf_civicrm' ),
				'value' => $profile_id
			];
		}

		return $options;
	}
}
