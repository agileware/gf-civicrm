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

	public function init() {
		parent::init();

		$gf_version = \GFCommon::get_version_info()['version'];

		if ( $this->is_gravityforms_supported() && class_exists('GF_Field') ) {
			require_once( 'includes/class-gf-civicrm-address-field.php' );
			$this->gf_civicrm_address_field = new Address_Field();
		}
	}

	public function warn_auth_checksum($wrapper = '<div class="notice notice-warning is-dismissible">%s</div>') {
		$forms = GFAPI::get_forms();

		$warnings = [];

		foreach ($forms as $form) {
			$settings = $this->get_form_settings($form);
			if (!empty($settings['civicrm_auth_checksum'])) {
				$settings_link = admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=gf-civicrm&id=' . $form['id'] );
				$warnings[] = sprintf( __( 'The Gravity Form "%s" has the <strong>nonfunctional</strong> CiviCRM auth checksum setting enabled. <a href="%s">Click here to edit the form settings.</a>', 'text-domain' ), esc_html( $form['title'] ), esc_url( $settings_link ) );
			}
		}

		if(!empty($warnings)) {
			$notice_heading = __( 'Legacy setting enabled for form(s)' );
			$notice_footer  = sprintf(
				__( 'For details on setting up an alternative method for checksum authentication, see the README section on <a target="_blank" href="%1$s">Processing form submissions as a specific Contact</a>' ),
				'https://github.com/agileware/gf-civicrm/blob/main/README.md#processing-form-submissions-as-a-specific-contact' );
			return sprintf( $wrapper, '<h3>' . $notice_heading . '</h3><ul>' . implode( '', $warnings ) . '</ul><p>' . $notice_footer . '</p>'  );
		} else {
			return NULL;
		}
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

  /**
   * Add form settings tab, *only if* legacy settings are already set.
   */
  public function add_form_settings_menu( $tabs, $form_id ) {
	  $settings = $this->get_form_settings( $form_id );

	  if ( ! empty( $settings['civicrm_auth_checksum'] ) ) {
		  return parent::add_form_settings_menu( $tabs, $form_id );
	  } else {
		  return $tabs;
	  }
  }

	public function form_settings_fields( $form ) {
		$settings = $this->get_form_settings( $form ) ?: [];

		$fields = [];

		// Legacy field, don't allow for new forms.
		if ( ! empty( $settings['civicrm_auth_checksum'] ) ) {
			$fields[] = [
				// 'label' => esc_html__( 'Allow authentication with checksum', 'gf-civicrm' ),
				'type'        => 'checkbox',
				'name'        => 'civicrm_auth_checksum',
				'description' => wp_kses(sprintf(__(
					'<strong>Nonfunctional</strong>: This option is no longer functional due to a <strong>security risk</strong>. Switch to the replacement workflow using Form Processor capabilities outlined <a target="_blank" href="%s">in the README</a>.',
					'gf-civicrm'
				), 'https://github.com/agileware/gf-civicrm/tree/main?tab=readme-ov-file#processing-form-submissions-as-a-specific-contact'), 'data'),
				'choices'     => [
					[
						'label' => esc_html__( 'Allow authentication with checksum', 'gf-civicrm' ),
						'name'  => 'civicrm_auth_checksum'
					]
				]
			];
		}

		if(!empty($fields)) {
			return [
				[
					'title'  => esc_html__( 'CiviCRM Settings', 'gf-civicrm' ),
					'fields' => &$fields,
				],
			];
		} else {
			return [];
		}
	}

	public function plugin_settings_fields() {
		return [ [
			'title'       => esc_html__( 'CiviCRM Settings', 'gf-civicrm' ),
			'description' => esc_html__( 'Global settings for CiviCRM add-on', 'gf-civicrm' ),
			'fields'      => [ [
				'type'          => 'checkbox',
				'name'          => 'gf_civicrm_flags',
				'default_value' => [ 'civicrm_multi_json' ],
				'choices' => [
					[
						'label'   => esc_html__( 'Use JSON encoding for Checkbox and Multiselect values in webhooks (recommended)', 'gf_civicrm' ),
						'name'    => 'civicrm_multi_json',
					],
				],
			] ],
		] ];
	}
}
