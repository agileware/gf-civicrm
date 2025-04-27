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
	 * Class constructor which hooks the instance into the WordPress init action
	 */
	function __construct() {
		parent::__construct();

    // Define capabilities in case role/permissions have been customised (e.g. Members plugin)
		$this->_capabilities_settings_page	= 'gravityforms_edit_settings';
		$this->_capabilities_form_settings	= 'gravityforms_edit_forms';
		$this->_capabilities_uninstall		= 'gravityforms_uninstall';
	}

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

    // Notify if CiviCRM Site Key and/or API Key is empty
    add_action( 'admin_notices', function() {
      // Only if CMRF is not active
      if ( !is_plugin_active( 'connector-civicrm-mcrestface/wpcmrf.php' ) ) {
        $gf_civicrm_site_key = FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_site_key' );
        $gf_civicrm_api_key = FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_api_key' );
        $missing = [];
        if ( !$gf_civicrm_site_key ) {
          $missing[] = 'Site Key';
        }
        if ( !$gf_civicrm_api_key ) {
          $missing[] = 'API Key';
        }
        if ( !empty( $missing ) ) {
          $this->warn_keys_settings( $missing );
        }
      }
    } );

    // Notify the Webhook URL Merge Tags Replacer status
    add_action( 'admin_notices', function() {
      if ( isset($_GET['subview']) && $_GET['subview'] === 'gf-civicrm' ) {
        if (get_transient('gfcv_webhook_merge_tags_replacement_failure')) {
          $status_message = 'Something went wrong.';
          $notice_class = 'error';
        } else if (get_transient('gfcv_webhook_merge_tags_replacement_success')) {
          $status_message = 'Success!';
          $notice_class = 'success';
        } else {
          return; // Do nothing if there are no statuses
        }

        $message = sprintf(
            '<p><strong>%1$s</strong></p><p>%2$s</p>',
            esc_html__( 'Executed Webhook URL Merge Tags Replacements.', 'gravityforms' ),
            esc_html__( $status_message, 'gravityforms' ),
        );

        printf( '<div class="notice notice-%s gf-notice" id="gform_status_notice">%s</div>', $notice_class, $message );
        }
    } );

    add_action( 'admin_init', [$this, 'maybe_run_merge_tags_replacer'] );
    
  }
  
  public function maybe_run_merge_tags_replacer() {
    if ( isset( $_GET['gf_webhook_merge_tags_replacement_action'] ) && 'run' === $_GET['gf_webhook_merge_tags_replacement_action'] ) {
      // Clear status messaging transients
      delete_transient('gfcv_webhook_merge_tags_replacement_failure');
      delete_transient('gfcv_webhook_merge_tags_replacement_success');

      // Verify nonce for security.
      if ( ! isset( $_GET['gf_webhook_merge_tags_replacement_nonce'] ) || ! wp_verify_nonce( $_GET['gf_webhook_merge_tags_replacement_nonce'], 'webhook_merge_tags_replacement_nonce' ) ) {
          wp_die( __( 'Security check failed', 'gf-civicrm' ) );
      }
      
      // Call the replacer function
      $updater = Upgrader::get_instance();
      $result = $updater->execute_webhook_url_merge_tags_replacements();

      if ( !$result || empty($result) ){
        set_transient( 'gfcv_webhook_merge_tags_replacement_failure', true, 60 );
      } else {
        set_transient( 'gfcv_webhook_merge_tags_replacement_success', true, 60 );
      }
      
      // Redirect back to the CiviCRM Settings page with a status message
      wp_redirect(
        add_query_arg(
          [
            'page' => 'gf_settings',
            'subview' => 'gf-civicrm',
          ],
          admin_url( 'admin.php' )
        )
      );
      exit;
    }
  }

	public function init() {
		parent::init();

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
   * Displays a warning if either or both of the CiviCRM Site Key and CiviCRM API Key settings are empty/null.
   * 
   * These are required for the {gf_civicrm_site_key} and {gf_civicrm_api_key} merge tags.
   * 
   * @param array $settings        The missing settings.
   */
  public function warn_keys_settings( $settings = [] ) {
    if ( empty($settings) ) {
      // Do nothing. We don't know what we're warning against.
      return;
    }

    $settings_text = implode(' and ', $settings);

		$message = sprintf(
      '<p><strong>%1$s%2$s%3$s</strong></p><p>%4$s</p>',
      $settings_text,
      count($settings) > 1 ? ' are' : ' is',
      esc_html__( ' missing in the Gravity Forms CiviCRM Settings.', 'gravityforms' ),
      esc_html__( 'These are required for the {gf_civicrm_site_key} and {gf_civicrm_api_key} merge tags. If you are using these, please check your configuration.', 'gravityforms' ),
    );

    printf( '<div class="notice notice-warning gf-notice" id="gform_warn_missing_keys_notice">%s</div>', $message );
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
          [ 'field_types' => ['group_contact_select', 'civicrm_payment_token', 'address'] ],
        ],
      ],
      [
        'handle'  	=> 'gf_civicrm_address_fields',
        'src'		=> $this->get_base_url() . '/js/gf-civicrm-address-fields.js',
        'version'	=> $this->_version,
        'deps'		=> ['jquery', 'wp-util'],
        'in_footer'	=> true,
        'enqueue' 	=> [
		      [ $this->gf_civicrm_address_field, 'applyGFCiviCRMAddressField' ]
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

    if ( is_plugin_active( 'connector-civicrm-mcrestface/wpcmrf.php' ) ) {
      // CiviCRM McRestFace
      $fields[] = [
        'label' => esc_html__( 'CiviCRM REST Connection Profile', 'gf-civicrm' ),
        'type'        => 'select',
        'name'        => 'civicrm_rest_connection',
        'description' => esc_html__(
          'Select which CMRF connection profile to use for this form.',
          'gf-civicrm'
        ),
        'choices'     => $this->get_cmrf_profile_options()
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
    $nonce = wp_create_nonce( 'webhook_merge_tags_replacement_nonce' );
    $action_url = add_query_arg( array(
        'gf_webhook_merge_tags_replacement_action' => 'run',
        'gf_webhook_merge_tags_replacement_nonce'  => $nonce,
    ), admin_url( 'admin.php?page=gf_settings&subview=gf-civicrm' ) );

    $fields = [];

    $fields[] = [
      'title'       => esc_html__( 'CiviCRM Settings', 'gf-civicrm' ),
      'description' => esc_html__( 'Global settings for CiviCRM add-on', 'gf-civicrm' ),
      'fields'      => [ 
        [
          'type'          => 'checkbox',
          'name'          => 'gf_civicrm_flags',
          'default_value' => [ 'civicrm_multi_json' ],
          'choices' => [
            [
              'label'   => esc_html__( 'Use JSON encoding for Checkbox and Multiselect values in webhooks (recommended)', 'gf_civicrm' ),
              'name'    => 'civicrm_multi_json',
            ],
            [
              'label'   => esc_html__( 'Enable pre-releases for updates.', 'gf_civicrm' ),
              'name'    => 'enable_prereleases',
              'tooltip' => esc_html__( 'Opt-in to including pre-releases/beta releases for GF CiviCRM updates. Please note that pre-releases may be unstable, so make sure to take a backup of your database before performing updates with this option enabled.', 'simpleaddon' ),
            ],
          ],
        ],
      ],
    ];

    if ( is_plugin_active( 'connector-civicrm-mcrestface/wpcmrf.php' ) ) {
      $fields[] = [ // CiviCRM McRestFace or Local
        'title'         => esc_html__( 'CiviCRM REST Connection Profile', 'gf-civicrm' ),
        'description'   => wp_kses_post( sprintf(
                            __( 'Select which CMRF connection profile to use for this form. <a href="%s">Configure connection profiles here.</a>', 'gf-civicrm' ),
                            esc_url( add_query_arg( [ 'page' => 'wpcmrf_admin' ], admin_url( 'options-general.php' ) ) )
                          ) ),
        'fields'        => [ [
          'type'          => 'select',
          'name'          => 'civicrm_rest_connection',
          'choices'       => $this->get_cmrf_profile_options( true ),
        ] ],
      ];
    }

    // Only use these fields if CMRF is not active.
    if ( !is_plugin_active( 'connector-civicrm-mcrestface/wpcmrf.php' ) ) {
      $fields[] = [
        'title'       => esc_html__( 'CiviCRM Site Key', 'gf-civicrm' ),
        'description' => esc_html__( 'Provide the CiviCRM site key for making API calls, can be output using the merge tag {gf_civicrm_site_key}.', 'gf-civicrm' ),
        'fields'      => [ [
          'type'          => 'text',
          'name'          => 'gf_civicrm_site_key',
          'default_value' => '',
        ] ],
      ];
  
      $fields[] = [
        'title'       => esc_html__( 'CiviCRM API Key', 'gf-civicrm' ),
        'description' => esc_html__( 'Provide the CiviCRM API key for making API calls, can be output using the merge tag {gf_civicrm_api_key}.', 'gf-civicrm' ),
        'fields'      => [ [
          'type'          => 'text',
          'name'          => 'gf_civicrm_api_key',
          'default_value' => '',
        ] ],
      ];
    }

    $fields[] = [
      'title'       => esc_html__( 'Webhook Alerts', 'gf-civicrm' ),
      'description' => nl2br(esc_html__( "Webhook alerts will be sent to the email provided below.", 'gf-civicrm' )),
      'fields'      => [ [
        'type'          => 'checkbox',
        'name'          => 'gf_civicrm_alerts',
        'choices' => [
          [
            'label'   => esc_html__( 'Enable email alerts', 'gf_civicrm' ),
            'name'    => 'enable_emails',
            'default_value' => true,
          ],
        ],
      ],
      [
        'type'          => 'text',
        'name'          => 'gf_civicrm_alerts_email',
        'default_value' => '',
        'validation_callback' => function( $field, $value ) {
          // Validate the value is actually an email
          if ( ! is_email( trim( $value ) ) ) {
            $field->set_error( __( 'Please enter a valid email address.', 'gravityforms' ) );
          }
        }
      ] ],
    ];

    $fields[] = [
      'title'       => esc_html__( 'Import/Export Directory', 'gf-civicrm' ),
      'description' => nl2br(esc_html__( "Define the path to the import/export directory, relative to the server document root. Used by Export GF CiviCRM and Import GF CiviCRM.\n\nYou can modify the subdirectories using the 'gf-civicrm/export-directory' and 'gf-civicrm/fp-export-directory' filters.", 'gf-civicrm' )),
      'fields'      => [ [
        'type'          => 'text',
        'name'          => 'gf_civicrm_import_export_directory',
        'default_value' => 'CRM/gf-civicrm-exports',
      ] ],
    ];

    $fields[] = [
      'title'       => esc_html__( 'Webhook URL Merge Tags Replacements', 'gf-civicrm' ),
      'description' => __( 'Replaces the REST API url, and CiviCRM Site keys and API keys in Gravity Forms webhook request URLs with their equivalent merge tags, for all webhooks feeds. Saves the CiviCRM Site Key and API key in the settings.<br /><br /><strong>CAUTION:</strong> It is recommended to take a backup before running this function.', 'gf-civicrm' ),
      'fields'      => [ [
        'name'  => 'webhook_merge_tags_replacer',
        'label' => '',
        'type'  => 'html',
        'html'  => '<a href="' . esc_url( $action_url ) . '" class="button">Replace the Merge Tags</a>',
      ] ],
    ];

		return $fields;
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

		$profiles = get_profiles();
		foreach ($profiles as $profile_id => $profile) {
			$options[] = [
				'label' => esc_html__( $profile['title'], 'gf_civicrm' ),
				'value' => $profile_id
			];
		}

		return $options;
	}
}
