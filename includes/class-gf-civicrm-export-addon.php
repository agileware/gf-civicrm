<?php

namespace GFCiviCRM;

use Civi\Api4\FormProcessorInstance;
use Civi\FormProcessor\API\FormProcessor;
use Civi\FormProcessor\Exporter\ExportToJson;
use Exception;
use Throwable;
use GFAddOn;
use GFAPI;
use GFCommon;
use GFExport;
use GFForms;
use GFFormsModel;
use RGFormsModel;

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

if ( ! class_exists( 'GFCiviCRM\ExportAddOn' ) ) {

    class ExportAddOn extends GFAddOn {

        protected $_version = '1.0';
        protected $_slug = 'gf_civicrm_export_addon';
        protected $_path = 'gf-civicrm/includes/gf-civicrm-export-addon.php';
        protected $_full_path = __FILE__;
        protected $_title = 'Gravity Forms CiviCRM Export Addon';
        protected $_short_title = 'GFCiviCRM Export';

        private static $_instance = null;

        public static function get_instance() {
            if ( self::$_instance == null ) {
                self::$_instance = new self();
            }
            return self::$_instance;
        }

        /**
         * Initialize the WordPress Filesystem API.
         */
        private function init_filesystem() {
            global $wp_filesystem;
            if ( empty( $wp_filesystem ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
            }
            return $wp_filesystem;
        }

        public function styles() {
            $styles = [
                [
                    'handle'  => 'gf_civicrm_export_addon',
                    'src'     => $this->get_base_url(__DIR__) . '/css/gf-civicrm-export-addon.css',
                    'version' => rand(0, 999),
                    'enqueue' => [ 
                        [ $this, 'should_enqueue_scripts' ], // Specify where to enqueue
                    ],
                ],
            ];

            return array_merge(parent::styles(), $styles);
        }

        public function scripts() {
            $scripts = [
                [
                    'handle' => 'gf_civicrm_export_addon',
                    'src' => $this->get_base_url(__DIR__ ) . '/js/gf-civicrm-export-addon.js',
                    'version' => $this->_version,
                    'deps' => [ 'wp-i18n' ],
                    'enqueue' => [
                        [ $this, 'should_enqueue_scripts' ] // Specify where to enqueue
                    ],
                    'strings' => [
                        'action' => admin_url( 'admin-post.php?action=gf_civicrm_export' ),
                    ],
                ]
            ];
            return array_merge(parent::scripts(), $scripts);
        }

        public function should_enqueue_scripts() {
            return is_admin() && rgget( 'page' ) == 'gf_export' && (rgget( 'subview' ) == 'import_gfcivicrm' || rgget( 'subview' ) == 'export_gfcivicrm');
        }

        public function init_admin() {
            parent::init_admin();

            add_action('admin_post_gf_civicrm_export', [ $this, 'export_form_and_feeds' ]);

            add_action('gform_export_page_export_gfcivicrm', [ $this, 'export_gfcivicrm_form_html' ]);
            add_action('gform_export_page_import_gfcivicrm', [ $this, 'import_gfcivicrm_form_html' ]);

            add_action( 'admin_notices', function() {
                if ( isset($_GET['subview']) && $_GET['subview'] === 'export_gfcivicrm' ) {
                    $this->display_export_status();
                }
            } );

            add_action( 'admin_notices', function() {
                if ( isset($_GET['subview']) && $_GET['subview'] === 'import_gfcivicrm' ) {
                    $this->display_import_status();
                }
            } );

            add_filter('gform_export_menu', [ self::class, 'settings_tabs' ], 10, 1);
        }

        public static function settings_tabs( $settings_tabs ) {
            if( GFCommon::current_user_can_any('gravityforms_edit_forms') ) {
                $settings_tabs[25] = [ 'name' => 'export_gfcivicrm', 'label' => esc_html__( 'Export GF CiviCRM', 'gf-civicrm' ) ];
                $settings_tabs[50] = [ 'name' => 'import_gfcivicrm', 'label' => esc_html__( 'Import GF CiviCRM', 'gf-civicrm' ) ];
            }

            return $settings_tabs;
        }

        public function export_form_and_feeds() {
            // Verify the nonce and permissions using specific GF capabilities
            check_admin_referer( 'gf_export_forms', 'gf_export_forms_nonce' );
            if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                wp_die( esc_html__( 'Unauthorized request.', 'gf-civicrm' ), 403 );
            }

            $wp_filesystem = $this->init_filesystem();

            $forms = filter_input_array(INPUT_POST, [
                    'gf_form_id' => [
                        'filter' => FILTER_VALIDATE_INT,
                        'flags' => FILTER_REQUIRE_ARRAY,
                        'options' => [ 'min_range' => 0 ]
                    ]],
                false);

            $form_ids = $forms['gf_form_id'] ?? [];

            if ( empty( $form_ids ) ) {
                wp_die( esc_html__( 'No forms found to export.', 'gf-civicrm' ) );
            }

            $docroot = $_SERVER['DOCUMENT_ROOT'];

            $forms_data = GFFormsModel::get_form_meta_by_id( $form_ids );

            $exports = [];
            $failures = [];
            foreach ( $forms_data as $form ) {
                $form_id = $form['id'];

                // Get feeds attached to this form
                $feeds = GFAPI::get_feeds( null, [ $form_id ] );
                if ( $feeds instanceof \WP_Error ) {
                    $feeds = [];
                }
                $webhook_feeds = array_filter( $feeds, function( $feed ) {
                    return isset( $feed['addon_slug'] ) && $feed['addon_slug'] === 'gravityformswebhooks';
                });

                // Get the action parameter from the first webhook feed, if available
                $action_value = null;
                $processors = [];

                foreach ( $webhook_feeds as $feed ) {
                    $feed_action = $this->get_action_from_url( $feed['meta']['requestURL'] ?? '' );
                    $action_value ??= $feed_action;

                    $processors[ $feed_action ] = $this->get_form_processor( $feed_action );
                }

                $processors = array_filter($processors);

                $action_value ??= sanitize_title( $form['title'], $form_id );
                $form_slug = sanitize_title( $form['title'] );
                $form_slug = str_replace( '-', '_', $form_slug ); // Replace dashes with underscores

                // Define the subdirectory paths by form title. Form processors exported to a separate subdirectory.
                $directory_base = FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_import_export_directory' );
                $fp_directory = 'form-processors';
                $directory_name = $form_slug;
                $export_directory = apply_filters(
                    'gf-civicrm/import-export-directory',
                    "$docroot/$directory_base/$directory_name",
                    $docroot, $directory_base, $directory_name, $action_value, $form_slug, $form_id
                );
                $fp_export_directory = apply_filters(
                    'gf-civicrm/fp-import-export-directory',
                    "$docroot/$directory_base/$fp_directory",
                    $docroot, $directory_base, $directory_name, $fp_directory, $action_value, $form_slug, $form_id
                );

                // Generate the directories and protect with htaccess using WP_Filesystem
                foreach ( [$export_directory, $fp_export_directory] as $directory ) {
                    $parent_directory = dirname($directory);
                    $htaccess = "$parent_directory/.htaccess";

                    // Create the directory if it doesn’t exist
                    if ( ! $wp_filesystem->is_dir( $directory ) ) {
                        $wp_filesystem->mkdir( $directory, FS_CHMOD_DIR );
                    }

                    // Create the htaccess if it doesn't exist. Restricts access to the exports.
                    if ( ! $wp_filesystem->exists( $htaccess ) ) {
                        $htaccess_contents = "Order allow,deny\nDeny from all";
                        $wp_filesystem->put_contents( $htaccess, $htaccess_contents, FS_CHMOD_FILE );
                    }
                }

                // Save each webhook feed data for this form into the one file
                $feeds_export = [ 'version' => GFForms::$version ];
                $feeds_default =  [ 'migrate_feed_type' => 'official' ]; // Additional meta from compatibility with import plugin
                $feeds_status_final = false;
                unset( $form['gf-civicrm-export-webhook-feeds'] );
                foreach ( $feeds as $feed ) {
                    $feeds_export[] = $feed + $feeds_default;
                    $feeds_file_name = "feeds--$directory_name.json";
                    $feeds_file_path = "$export_directory/$feeds_file_name";
                    $feeds_json = json_encode( $feeds_export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES );
                    $status = $wp_filesystem->put_contents( $feeds_file_path, $feeds_json, FS_CHMOD_FILE );
                    if ( ! $status ) {
                        // Log a failure
                        $failures['Feed'][$feed['id']] = $feed['meta']['feedName'];
                    } else {
                        $feeds_status_final = true;
                        // Store the feedname with the form in the export
                        $form['gf-civicrm-export-webhook-feeds'][] = $feed['meta']['feedName'];
                    }
                }
                // Log a single status message for the final file
                if ( $feeds_status_final ) {
                    $exports['Feed'][$feed['id']] = $feeds_file_name;
                }

                // Save the form processor data JSON file to the form-processors directory
                unset( $form['gf-civicrm-export-form-processors'] );
                $exported_processors = $this->export_processors($processors, $fp_export_directory, $wp_filesystem);
                foreach ( $exported_processors as $status => $processor_group ) {
                    foreach ( $processor_group as $id => $processor ) {
                        if ( $status === 'success' ) {
                            $exports["Form Processor"][$id] = $processor;
                        }
                        else {
                            $failures["Form Processor"][$id] = $processor;
                        }
                        $form['gf-civicrm-export-form-processors'][] = $id;
                    }
                }

                // Save the form data JSON file
                $forms_export = GFExport::prepare_forms_for_export( [ $form ]);

                $form_file_name = "form--$directory_name.json";
                $form_file_path = "$export_directory/$form_file_name";
                $form_json = json_encode( $forms_export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
                $status = $wp_filesystem->put_contents( $form_file_path, $form_json, FS_CHMOD_FILE );
                if ( ! $status ) {
                    $failures['GF Form'][$form['id']] = $form['title'];
                } else {
                    $exports['GF Form'][$form['id']] = $form_file_name;
                }
            }

            // Store the names of exports for 60 seconds for status reporting
            if ( !empty($failures) ) {
                set_transient( 'gfcv_exports_failures', $failures, 60 );
                set_transient( 'gfcv_exports_status_failure', true, 60 );
            }
            if (!empty($exports) ){
                set_transient( 'gfcv_exports', $exports, 60 );
                set_transient( 'gfcv_exports_status_success', true, 60 );
            }

            // Redirect back to the Export page with a safe redirect
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page' => 'gf_export',
                        'subview' => 'export_gfcivicrm',
                    ],
                    admin_url( 'admin.php' )
                )
            );
            
            exit;
        }

        /**
         * Get the action value from the Request URL. This is a reference to the form processor.
         */
        private function get_action_from_url( $url ) {
            // Parse the URL to extract query parameters
            $parsed_url = parse_url( $url );
            if ( ! isset( $parsed_url['query'] ) ) {
                return null;
            }

            // Parse query parameters into an associative array
            parse_str( $parsed_url['query'], $query_params );

            // Return the `action` parameter if it exists
            return $query_params['action'] ?? null;
        }

        /**
         * Get the form processor by name.
         */
        private function get_form_processor( $name )
        {
            // Check if a CiviCRM installation exists
            if ( check_civicrm_installation()['is_error'] ) {
                return null;
            }

            // Get the CiviCRM REST Connection Profile. This may be the local CiviCRM connection if no profile is set.
			$profile_name = get_rest_connection_profile();

            // Get the FormProcessor by name
            try {
                $api_params = [
                    'return' => ['id'],
                    'name' => $name,
                ];
                $result = api_wrapper( $profile_name, 'FormProcessorInstance', 'get', $api_params, [] ) ?? [];

                if ( count($result) < 1) {
                    return null;
                }

                $fp = reset($result);
                return $fp['id'];
            } catch ( Exception $e) {
                // Log error if needed and return null if there is an issue
                GFCommon::log_debug( __METHOD__ . "(): GF CiviCRM Errors => Error fetching FormProcessor `$name`: " . $e->getMessage() );
            }

            return null;
        }

        /**
         * @param array $processors
         * @param mixed $export_directory
         * @param mixed $wp_filesystem
         * @return array
         * @throws \CRM_Core_Exception
         */
        protected function export_processors(array $processors, mixed $export_directory, $wp_filesystem) {
            if ( check_civicrm_installation()['is_error'] ) {
                return [];
            }

            // Get the CiviCRM REST Connection Profile. This may be the local CiviCRM connection if no profile is set.
			$profile_name = get_rest_connection_profile();

            $exports = [];
            foreach ( $processors as $name => $id ) {
                try {
                    $file_path = "$export_directory/$name.json";

                    $api_params = [
                        'id' => $id,
                    ];
                    $export = api_wrapper( $profile_name, 'FormProcessorInstance', 'export', $api_params, [ 'cache' => 0 ] ) ?? [];
                    
                    if ( isset( $export['is_error'] ) && $export['is_error']  ) {
                        throw new Exception(  $export['error_message'] );
                    }

                    $export_json = json_encode(reset($export), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

                    $wp_filesystem->put_contents($file_path, $export_json, FS_CHMOD_FILE);
                    $exports['success'][$name] = "$name.json";
                } catch ( Exception $e) {
                    GFCommon::log_debug( __METHOD__ . "(): GF CiviCRM Export Errors => Error exporting FormProcessor `$name`: " . $e->getMessage() );
                    $exports['failure'][$name] = 'Error exporting FormProcessor => ' . $name . ': ' . $e->getMessage();
                }
            }

            return $exports;
        }

        /**
         * Replicate the Gravity Forms Export Forms subview. Add in our modifications.
         */
        public function export_gfcivicrm_form_html() {

            if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gf-civicrm' ) );
            }

            $docroot = $_SERVER['DOCUMENT_ROOT'];
            $directory_base = FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_import_export_directory' );

            GFExport::page_header();
            GFExport::maybe_process_automated_export();
            ?>
            <script type="text/javascript">
    
                ( function( $, window, undefined ) {
    
                    $( document ).on( 'click keypress', '#gf_export_forms_all', function( e ) {
    
                        var checked  = e.target.checked,
                            label    = $( 'label[for="gf_export_forms_all"]' ),
                            formList = $( '#export_form_list' );
    
                        // Set label.
                        label.find( 'strong' ).html( checked ? label.data( 'deselect' ) : label.data( 'select' ) );
    
                        // Change checkbox status.
                        $( 'input[name]', formList ).prop( 'checked', checked );
    
                    } );
    
                }( jQuery, window ));
    
            </script>

            <div class="gform-settings__content">
                <form method="post" id="tab_gform_export" class="gform_settings_form">
                    <?php wp_nonce_field( 'gf_export_forms', 'gf_export_forms_nonce' ); ?>
                    <div class="gform-settings-panel gform-settings-panel--full">
                        <header class="gform-settings-panel__header"><legend class="gform-settings-panel__title"><?php esc_html_e( 'Export CiviCRM Integrated Forms', 'gf-civicrm' )?></legend></header>
                        <div class="gform-settings-panel__content">
                            <div class="gform-settings-description">
                                <?php echo esc_html( sprintf( __( 'Select the forms you would like to export from the server to “%1$s”. Associated webhook feeds and CiviCRM form processors will also be exported. Note that this will overwrite all forms, feeds, and CiviCRM form processors included with the form exports already on the file system.', 'gf-civicrm' ), $directory_base ) ); ?>
                            </div>
                            <table class="form-table">
                                <tr valign="top">
                                    <th scope="row">
                                        <label for="export_fields"><?php esc_html_e( 'Select Forms', 'gf-civicrm' ); ?></label> <?php gform_tooltip( 'export_select_forms' ) ?>
                                    </th>
                                    <td>
                                        <ul id="export_form_list">
                                            <li>
                                                <input type="checkbox" id="gf_export_forms_all" />
                                                <label for="gf_export_forms_all" data-deselect="<?php esc_attr_e( 'Deselect All', 'gf-civicrm' ); ?>" data-select="<?php esc_attr_e( 'Select All', 'gf-civicrm' ); ?>"><?php esc_html_e( 'Select All', 'gf-civicrm' ); ?></label>
                                            </li>
                                            <?php
                                            $forms = RGFormsModel::get_forms( null, 'title' );
                                            $forms = apply_filters( 'gform_export_forms_forms', $forms );
    
                                            foreach ( $forms as $form ) {
                                                ?>
                                                <li>
                                                    <input type="checkbox" name="gf_form_id[]" id="gf_form_id_<?php echo absint( $form->id ) ?>" value="<?php echo absint( $form->id ) ?>" />
                                                    <label for="gf_form_id_<?php echo absint( $form->id ) ?>"><?php echo esc_html( $form->title ) ?></label>
                                                </li>
                                                <?php
                                            }
                                            ?>
                                        </ul>
                                    </td>
                                </tr>
                            </table>
    
                            <br /><br />
                            <button class="button primary" formaction="<?php echo esc_url( admin_url('admin-post.php?action=gf_civicrm_export') ); ?>"><?php esc_html_e( 'Export Selected', 'gf-civicrm' ); ?></button>
                        </div>
                    </div>
                </form>
            </div>
            <?php
    
            GFExport::page_footer();
        }

        /**
         * Generate the Import subview form HTML.
         */
        public function import_gfcivicrm_form_html() {

            if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'gf-civicrm' ) );
            }

            $docroot = $_SERVER['DOCUMENT_ROOT'];
            $directory_base = FieldsAddOn::get_instance()->get_plugin_setting( 'gf_civicrm_import_export_directory' );

            $import_directory = apply_filters(
                'gf-civicrm/import-export-directory',
                "$docroot/$directory_base",
                $docroot, $directory_base
            );
            $fp_import_directory  = apply_filters(
                'gf-civicrm/fp-import-export-directory',
                "$docroot/$directory_base",
                $docroot, $directory_base
            );

            GFExport::page_header();

            if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
                $this->do_import($import_directory);
            }

            $forms = RGFormsModel::get_forms( null, 'title' );
            $forms = apply_filters( 'gform_export_forms_forms', $forms );

            $select_forms = '<option value="create">' . esc_html__( '-- Create new form --', 'gf-civicrm' ) . '</option>'; 
            foreach ( $forms as $form ) {
                $title_value = sanitize_title( $form->title );
                $title_value = str_replace( '-', '_', $title_value );
                $select_forms .= '<option value="' . esc_attr( $title_value ) . '">' . esc_html( $form->title ) . ' (ID: ' . absint( $form->id ) . ')</option>';
            }
            
            $importable_forms = $this->importable_forms($import_directory);
            $importable_form_processors = $this->importable_form_processors($fp_import_directory);

            // Get the CiviCRM REST Connection Profile. This may be the local CiviCRM connection if no profile is set.
            $profile_name = get_rest_connection_profile();
            $profiles = get_profiles();
            $is_remote = false;

            if ( isset( $profiles[$profile_name]['connector'] ) && $profiles[$profile_name]['connector'] !== 'local' ) {
                $is_remote = true;
            }

            ?>
            <div class="gform-settings__content">
                <form method="post" style="margin-top: 10px;" class="gform_settings_form">
                <?php wp_nonce_field('gf_civicrm_import'); ?>
                    <div class="gform-settings-panel gform-settings-panel--full">
                        <div class="gform-settings-panel__header"><h2 class="gform-settings-panel__title"><?php esc_html_e( 'Import CiviCRM Integrated Forms', 'gf-civicrm' ); ?></h2></div>
                        <div class="gform-settings-panel__content">
                            <div class="gform-settings-description"><?php echo esc_html( sprintf( __( 'Select the forms you would like to import from the server at “%1$s”. Note that this will overwrite the current database entries for all existing forms, feeds, and CiviCRM form processors included with the form export files.', 'gf-civicrm' ), $directory_base ) ); ?></div>
                            <fieldset>
                                <legend><h3><?php esc_html_e( 'Select Forms', 'gf-civicrm' ); ?></h3></legend>
                                <p><?php esc_html_e( 'These forms were detected on the file system:', 'gf-civicrm' ); ?></p>
                                <ul id="import_form_list">
                                    <?php foreach( $importable_forms as $key => $values ) { 
                                        $feeds = implode(", ", array_map( 'esc_html', $values['feeds'] ) );
                                        $sanitized_feeds = implode(", ", array_map( 'sanitize_text_field', $values['feeds'] ) );
                                        $form_processors = implode(", ", array_map( 'esc_attr', $values['form_processors'] ) );
                                        ?>
                                        <li>
                                            <div>
                                                <input type="checkbox" id="import-form-<?php echo esc_attr( $values['id'] ); ?>" name="import_form[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $key ); ?>" data-formprocessors="<?php echo esc_attr( $form_processors ); ?>" data-feeds="<?php echo esc_attr( $sanitized_feeds ); ?>">
                                                <label for="import-form-<?php echo esc_attr( $values['id'] ); ?>">
                                                    <?php 
                                                    printf(
                                                            wp_kses_post( __('<strong>%s</strong> (<i>source: %s</i>)', 'gf-civicrm') ),
                                                            esc_html( $values['title'] ), esc_html( $values['filename'] ) );
                                                    ?>
                                                </label>
                                                <?php 
                                                if ( ! empty( $feeds ) ) {
                                                    printf(
                                                        wp_kses_post( __('<br>Includes feeds: %s', 'gf-civicrm') ),
                                                        $feeds );
                                                }
                                                ?>
                                            </div>
                                            <div>
                                                <label for="import-form-into-<?php echo esc_attr( $values['id'] ); ?>">
                                                    <?php echo wp_kses_post( __('<strong>Replace form</strong>', 'gf-civicrm') ); ?>
                                                </label>
                                                <select name="import_form_into[<?php echo esc_attr( $key ); ?>]" id="import-form-into-<?php echo esc_attr( $values['id'] ); ?>">
                                                    <?php echo wp_kses( $select_forms, [ 'option' => [ 'value' => true ] ] ); ?>
                                                </select>
                                            </div>
                                        </li>
                                    <?php } ?>
                                </ul>
                            </fieldset>
                            <fieldset>
                                <legend><h3><?php esc_html_e( 'Select Form Processors', 'gf-civicrm' ); ?></h3></legend>
                                <p><?php esc_html_e( 'These form processors were detected on the file system:', 'gf-civicrm' ); ?></p>
                                <?php
                                if ( $is_remote ) {
                                    printf(
                                        wp_kses_post( __('<p class="notice notice-warning"><strong>Warning:</strong> The import file must be accessible to the remote installation at the path specified in the <a href="%s">GF CiviCRM Import/Export Directory settings</a>.</p>', 'gf-civicrm') ),
                                        esc_url( add_query_arg( [ 'page' => 'gf_settings', 'subview' => 'gf-civicrm' ], admin_url( 'admin.php' ) ) )
                                    );
                                }
                                ?>
                                <ul id="import_form_processors_list">
                                    <?php foreach( $importable_form_processors as $key => $values ) { 
                                        ?>
                                        <li>
                                            <div>
                                                <input type="checkbox" id="import-form-processor-<?php echo esc_attr( $key ); ?>" name="import_form_processor[]" value="<?php echo esc_attr( $key ); ?>">
                                                <label for="import-form-processor-<?php echo esc_attr( $key ); ?>">
                                                    <?php 
                                                    printf(
                                                        wp_kses_post( __('<strong>%s</strong> (<i>source: %s</i>)', 'gf-civicrm') ),
                                                        esc_html( $values['title'] ), esc_html( $values['filename'] ) );
                                                    ?>
                                                </label>
                                                <?php 
                                                if ( $values['existing_id'] !== null ) {
                                                    printf(
                                                        wp_kses_post( __('<br>Will replace the existing form processor <strong>%s</strong> (ID: %s) on import.', 'gf-civicrm') ),
                                                        esc_html( $values['existing_name'] ), esc_html( $values['existing_id'] ) );
                                                }
                                                ?>
                                            </div>
                                        </li>
                                    <?php } ?>
                                </ul>
                            </fieldset>
                            <input class="button primary" type="submit" value="<?php esc_attr_e( 'Import Selected', 'gf-civicrm' ); ?>">
                        </div>
                    </div>
                </form>
            </div>
        <?php
        }

        /**
         * Check the filesystem on the server for import files in our designated directory.
         */
        protected function importable_forms( $import_directory ): array {
            $wp_filesystem = $this->init_filesystem();
            $importable = [];

            if ( ! $wp_filesystem->is_dir( $import_directory ) ) {
                return $importable;
            }

            $dirs = $wp_filesystem->dirlist( $import_directory );
            if ( ! $dirs ) return $importable;

            foreach ( $dirs as $dir_name => $dir_info ) {
                if ( $dir_info['type'] === 'd' ) {
                    $sub_dir = trailingslashit( $import_directory ) . $dir_name;
                    $files = $wp_filesystem->dirlist( $sub_dir );
                    if ( $files ) {
                        foreach ( $files as $file_name => $file_info ) {
                            if ( $file_info['type'] === 'f' && preg_match('/^form--(.*?)\.json$/i', $file_name, $matches) ) {
                                $key = $matches[1];
                                $file_path = $sub_dir . '/' . $file_name;
                                
                                $content = $wp_filesystem->get_contents( $file_path );
                                if ( ! $content ) continue;

                                $forms = json_decode( $content, true );
                                $form = reset($forms);

                                if( empty( $form ) ) continue;

                                $existing = GFFormsModel::get_form_meta( $form['id'] ?? 0 );

                                $importable[$key] = [
                                    'title' => $form['title'] ?? $key,
                                    'id' => $form['id'] ?? null,
                                    'existing' => $existing['title'] ?? '',
                                    'feeds' => $form['gf-civicrm-export-webhook-feeds'] ?? [],
                                    'form_processors' => $form['gf-civicrm-export-form-processors'] ?? [],
                                    'filename' => basename( $file_path )
                                ];
                            }
                        }
                    }
                }
            }

            return $importable;
        }

        /**
         * Check the filesystem on the server for Form Processor import files in our designated directory.
         */
        protected function importable_form_processors( $import_directory ): array {
            // Check if a CiviCRM installation exists
            if ( check_civicrm_installation()['is_error'] ) {
                return [];
            }

            // Get the CiviCRM REST Connection Profile. This may be the local CiviCRM connection if no profile is set.
            $wp_filesystem = $this->init_filesystem();
            $profile_name = get_rest_connection_profile();
            $profiles = get_profiles();
            $is_remote = false;

            if ( isset( $profiles[$profile_name]['connector'] ) && $profiles[$profile_name]['connector'] !== 'local' ) {
                $is_remote = true;
            }

            $importable = [];

            if ( ! $wp_filesystem->is_dir( $import_directory ) ) {
                return $importable;
            }

            $files = $wp_filesystem->dirlist( $import_directory );
            if ( ! $files ) return $importable;

            foreach ( $files as $file_name => $file_info ) {
                if ( $file_info['type'] === 'f' && preg_match('/^(.*?)\.json$/i', $file_name, $matches) ) {
                    $key = $matches[1];
                    $file_path = trailingslashit( $import_directory ) . $file_name;

                    $content = $wp_filesystem->get_contents( $file_path );
                    if ( ! $content ) continue;

                    $processor = json_decode( $content, true );
                    if( empty( $processor ) ) continue;

                    $api_params = [
                        'return' => ['id', 'name', 'title'],
                        'name' => $key,
                    ];
                    $existing = api_wrapper( $profile_name, 'FormProcessorInstance', 'get', $api_params, [ 'cache' => 0 ] ) ?? [];
                    $existing = reset( $existing );

                    $importable[$key] = [
                        'title' => $processor['name'] ?? $key,
                        'id' => $processor['id'] ?? null,
                        'existing' => $existing['title'] ?? '',
                        'existing_name' => $existing['name'] ?? '',
                        'existing_id' => $existing['id'] ?? null,
                        'filename' => basename( $file_path ),
                        'is_remote' => $is_remote,
                    ];
                }
            }

            return $importable;
        }

        /**
         * Handles the import.
         */
        protected function do_import( $import_directory ): void {
            // Clear status messaging transients
            delete_transient('gfcv_imports_status_success');
            delete_transient('gfcv_imports_status_failure');

            if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                wp_die( esc_html__( 'You do not have sufficient permissions to import forms.', 'gf-civicrm' ) );
            }

            check_admin_referer('gf_civicrm_import');

            $import_forms = filter_input(INPUT_POST, 'import_form', FILTER_CALLBACK, ['options' => [ $this, 'filter_form_names' ], 'flags' => FILTER_REQUIRE_ARRAY ]);
            $import_form_processors = filter_input(INPUT_POST, 'import_form_processor', FILTER_CALLBACK, ['options' => [ $this, 'filter_form_names' ], 'flags' => FILTER_REQUIRE_ARRAY ]);
            
            if ( ! $import_forms && ! $import_form_processors ) {
                esc_html_e( 'No imports selected', 'gf-civicrm' );
                return; //No forms selected for import
            }

            $import_forms = $import_forms ? array_filter($import_forms) : null;
            $import_form_processors = $import_form_processors ? array_filter($import_form_processors) : null;

	        $import_directory = trailingslashit($import_directory);

            $imports = [];
            $failures = [];
            $wp_filesystem = $this->init_filesystem();

            // Handle importing selected forms and their associated feeds.
            if ( $import_forms ) {
                foreach ( $import_forms as $directory_name ) {
                    $form_file_name = "form--$directory_name.json";
                    $form_file =  $import_directory . $directory_name . "/" . $form_file_name;

                    try {
                        // Get the import target for this form
                        $import_target = isset($_POST['import_form_into'][$directory_name]) ? sanitize_text_field( $_POST['import_form_into'][$directory_name] ) : '';
                        $form_id = $this->import_form( $form_file, $import_target, $wp_filesystem );
                        
                        $form = GFAPI::get_form( $form_id );
                        $imports['GF Form'][$form_id] = sprintf( wp_kses_post( __('<strong>%s</strong> (<i>source: %s</i>)', 'gf-civicrm') ), esc_html( $form['title'] ), esc_html( $form_file_name ) );
                    } catch ( Throwable $e ) {
                        $failures['GF Form'] = 'Failed to import Form => ' . esc_html( $e->getMessage() );
                        GFCommon::log_debug( __METHOD__ . '(): GF CiviCRM Import Errors => ' . $e->getMessage() );
                    }

                    $feeds_file_name = "feeds--$directory_name.json";
                    $feeds_file = $import_directory . $directory_name . "/$feeds_file_name";

                    // If we don't have a form ID, there's nothing to import feeds into, so we skip.
                    if ( $wp_filesystem->exists( $feeds_file ) && ! empty( $form_id ) ) {
                        try {
                            $feeds = $this->import_feeds( $feeds_file, $form_id, $wp_filesystem );

                            foreach ($feeds as $feed) {
                                $form = GFAPI::get_form( $feed['form_id'] );
                                $imports['Feed'][$feed['id']] = sprintf( wp_kses_post( __('<strong>%s</strong> for the form %s (<i>source: %s</i>)', 'gf-civicrm') ), esc_html( $feed['meta']['feedName'] ), esc_html( $form['title'] ), esc_html( $form_file_name ) );
                            }
                        } catch ( Throwable $e ) {
                            $failures['Feed'] = 'Failed to import Feed => ' . esc_html( $e->getMessage() );
                            GFCommon::log_debug( __METHOD__ . '(): GF CiviCRM Import Errors => ' . $e->getMessage() );
                        }
                    }
                }
            }

            // Only do this step if a CiviCRM installation exists
            if ( ! check_civicrm_installation()['is_error'] && $import_form_processors ) {
                // Get the CiviCRM REST Connection Profile. This may be the local CiviCRM connection if no profile is set.
                $profile_name = get_rest_connection_profile();

                // Handle importing form processors
                foreach ( $import_form_processors as $processor_name ) {
                    $processor_file_name = "$processor_name.json";
                    $processor_file = $import_directory . "form-processors/$processor_file_name";

                    if ( ! $wp_filesystem->exists( $processor_file ) ) {
                        continue;
                    }

                    // Check for any existing form processor with this name
                    $api_params = [
                        'return' => ['id', 'name', 'title'],
                        'name' => $processor_name,
                    ];
                    $existing = api_wrapper( $profile_name, 'FormProcessorInstance', 'get', $api_params, [ 'cache' => 0 ] ) ?? [];
                    $existing = reset($existing);

                    try {
                        $form_processor = $this->import_processor( $processor_file, $wp_filesystem );

                        if ( isset( $form_processor['is_error'] ) && $form_processor['is_error'] ) {
                            throw new Exception( $form_processor['error_message'] );
                        }
                        
                        $api_params = [
                            'id' => $form_processor['import']['new_id'],
                        ];
                        $fp_instance = api_wrapper( $profile_name, 'FormProcessorInstance', 'get', $api_params, [ 'cache' => 0 ] ) ?? [];
                        $fp_instance = reset($fp_instance);

                        if ( $existing ) {
                            $imports["Form Processor"][$fp_instance['id']] = sprintf( wp_kses_post( __('<strong>%s</strong> (ID:%s) (<i>was replaced by source: %s</i>)', 'gf-civicrm') ), esc_html( $fp_instance['name'] ), esc_html( $fp_instance['id'] ), esc_html( $processor_file_name ) );
                        } else {
                            $imports["Form Processor"][$fp_instance['id']] = sprintf( wp_kses_post( __('<strong>%s</strong> (ID:%s) (<i>was created from source: %s</i>)', 'gf-civicrm') ), esc_html( $fp_instance['name'] ), esc_html( $fp_instance['id'] ), esc_html( $processor_file_name ) );
                        }
                        
                    } catch ( Throwable $e ) {
                        $failures["Form Processor"] = 'Failed to import FormProcessor => ' . esc_html( $e->getMessage() );
                        GFCommon::log_debug( __METHOD__ . '(): GF CiviCRM Import Errors => ' . $e->getMessage() );
                    }
                }
            }

            // Store the names of imports for 60 seconds for status reporting
            // Do it this way so we can report on both successes and failures
            if ( !empty($failures) ) {
                set_transient( 'gfcv_imports_failures', $failures, 60 );
                set_transient( 'gfcv_imports_status_failure', true, 60 );
            }
            if (!empty($imports) ){
                set_transient( 'gfcv_imports', $imports, 60 );
                set_transient( 'gfcv_imports_status_success', true, 60 );
            }

            // Redirect back to the Export page with a success message
            wp_redirect(
                add_query_arg(
                    [
                        'page' => 'gf_export',
                        'subview' => 'import_gfcivicrm',
                    ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        public function filter_form_names( $input ) {
            // Exclude any filenames with path metacharacters or control characters
            return preg_match('{ [/:@<>] | \p{Z} }x', $input) ? NULL : sanitize_text_field( $input );
        }

	    /**
         * Import the Form meta. Lifted from GFExport class, but update a form if possible
         * 
	     * @param string $form_file
	     *
	     * @return int
	     * @throws Exception
	     */
        public function import_form( string $form_file, string $import_target = '', $wp_filesystem = null ) {
            if ( ! $wp_filesystem ) $wp_filesystem = $this->init_filesystem();

		    if ( ! $wp_filesystem->exists( $form_file ) ) {
			    throw new Exception( sprintf( esc_html__( 'Can not read from form import file %1$s', 'gf-civicrm' ), esc_html( basename( $form_file ) ) ) );
		    }

		    $form_json = $wp_filesystem->get_contents( $form_file );
		    $form_json = GFExport::sanitize_forms_json( $form_json );

		    $import_form_raw = json_decode( $form_json, true );

		    if ( empty( $import_form_raw ) ) {
			    throw new Exception( sprintf( esc_html__( 'No forms found in input file %1$s', 'gf-civicrm' ), esc_html( basename( $form_file ) ) ) );
            }

            $import_form = reset( $import_form_raw  );

            // Unset the version from the array so we don't loop through it
		    unset( $import_form['version'] );

            /**
             * We can't guarantee that form IDs match up between the files to import and the database.
             * Gravity Forms uses unique form titles, though. We'll match against the form title as a slug.
             * Unfortunately that does mean we have to get ALL feeds, process them, and check against the sanitized titles,
             * since there's currently no API function to get forms by name.
             */
            $existing_forms = GFAPI::get_forms(null); // Including inactive forms
            for ( $i = 0; $i < count($existing_forms); $i++ ) { 
                $existing = $existing_forms[$i];
                $title_slug = sanitize_title($existing['title']);
                $title_slug = str_replace( '-', '_', $title_slug ); // Replace dashes with underscores
                $existing['title_slug'] = $title_slug;
                $existing_forms[$i] = $existing;
            }

		    $import_form['markupVersion'] = rgar( $import_form, 'markupVersion' ) ? $import_form['markupVersion'] : 2;

            $import_form = GFFormsModel::convert_field_objects( $import_form );
            $import_form = GFFormsModel::sanitize_settings( $import_form );

            $id = null;

            // Always activate imported forms
            $import_form['is_active'] = true;

            if ( ! empty( $import_target ) ) {
                $filtered = array_filter( $existing_forms, function($item) use ($import_target) {
                    return $item['title_slug'] === $import_target;
                });

                if ( !empty ( $filtered ) ) {
                    // Update the existing form. 
                    // Only the form id will remain the same, all other configurations will be overridden by the import file.
                    $filtered = reset($filtered);
                    $id = $filtered['id']; // we're returning this id
                    $import_form['id'] = $id;
                    $status = GFAPI::update_form( $import_form );
                } else {
                    // Create a new form
                    $status = $id = GFAPI::add_form( $import_form );
                }
            }  else {
                // Create a new form
                $status = $id = GFAPI::add_form( $import_form );
            }

            if ( is_wp_error($status) ) {
                throw new Exception( sprintf( '%1$s : %2$s', esc_html( basename( $form_file ) ), esc_html( $status->get_error_message() ) ) );
            }

            return $id;
	    }

	    /**
	     * @param string $feeds_file
	     * @param string $form_file
	     *
	     * @return array
	     * @throws Exception
	     */
        public function import_feeds( string $feeds_file, int $form_id, $wp_filesystem = null ): array {
            if ( ! $wp_filesystem ) $wp_filesystem = $this->init_filesystem();

		    if ( ! $wp_filesystem->exists( $feeds_file ) ) {
			    throw new Exception( sprintf( esc_html__( 'Can not read from feeds import file %1$s. Aborting feed imports.', 'gf-civicrm' ), esc_html( basename( $feeds_file ) ) ) );
		    }

		    $feeds_json = $wp_filesystem->get_contents( $feeds_file );
		    $feeds_json = GFExport::sanitize_forms_json( $feeds_json );

		    $import_feeds = json_decode( $feeds_json, true );

            // Unset the version from the array so we don't loop through it
		    unset( $import_feeds['version'] );

		    $imported_feeds = [];

            // Get all feeds for the given form_id
            $current_form_feeds = GFAPI::get_feeds( null, $form_id );

            /**
             * Deactivate the existing feeds. If they're updated, they will be reactivated.
             * 
             * If there is a failure during import below, these existing feeds may still be deactivated.
             * 
             */
            if ( ! is_wp_error($current_form_feeds) ) {
                for ( $i=0; $i < count($current_form_feeds); $i++ ) { 
                    $current_feed = $current_form_feeds[$i];
                    GFAPI::update_feed_property( $current_feed['id'], 'is_active', 0 );
                    $current_form_feeds[$i] = $current_feed;
                }
            }

		    foreach ( $import_feeds as $idx => $feed ) {
                if ( empty($feed['form_id']) || empty($feed['addon_slug']) || empty($feed['meta']) ) {
                    GFCommon::add_error_message( sprintf ( esc_html__( 'Incorrect data loading feed id %1$d from %2$s. Aborting feed imports.', 'gf-civicrm' ), absint( $feed['id'] ), esc_html( basename( $feeds_file ) ) ) );
                    continue;
                }

                // Get basic identifying info
                $feed_name = $feed['meta']['feedName'];
                $feed_addon_slug = $feed['addon_slug'];
                $feed_id = null;

                if ( ! is_wp_error($current_form_feeds) && ! empty( $current_form_feeds ) ) {
                    $feed_id = $feed['id'];

                    // Check if a feed with the same ID, name, and addon_slug is attached to this form
                    $filtered_current = array_filter( $current_form_feeds, 
                        function ($item) use ($feed_id, $feed_name, $feed_addon_slug)  {
                            return $item['id'] === $feed_id 
                                && $item['meta']['feedName'] === $feed_name 
                                && $item['addon_slug'] === $feed_addon_slug;
                        }
                    );

                    // Since Feed IDs may be mismatched on import, we're going to also check for just the feed name and addon_slug
                    if ( empty( $filtered_current ) ) {
                        $filtered_current = array_filter( $current_form_feeds, 
                            function ($item) use ($feed_name, $feed_addon_slug)  {
                                return $item['meta']['feedName'] === $feed_name 
                                    && $item['addon_slug'] === $feed_addon_slug;
                            }
                        );
                    }

                    if ( !empty( $filtered_current ) ) {
                        $existing_feed = reset( $filtered_current ); // Just the first one
                        $feed_id = $feed['id'] = $existing_feed['id'];
                    } else {
                        // If we still have no results, import it as a new feed
                        $feed_id = $feed['id'] = null;
                    }
                }
                
                if ( is_null( $feed_id ) ) {
                    // Import a new feed
                    $status = $feed_id = GFAPI::add_feed( $form_id, $feed['meta'], $feed['addon_slug'] );
                } else {
                    // Update the existing feed. Make sure it's enabled first, because otherwise update_feed() will fail.
                    // Unfortunately no way to set the is_active flag to false/null on update_feed()
                    GFAPI::update_feed_property( $feed_id, 'is_active', 1 );
                    $status = GFAPI::update_feed( $feed_id, $feed['meta'], $form_id );
                }

                if ( is_wp_error($status) ) {
                    throw new Exception( sprintf( '%1$s : %2$s. Please check your feeds on this form.', esc_html( basename( $feeds_file ) ), esc_html( $status->get_error_message() ) ) );
                }

                // Ensure the feed is activated
                GFAPI::update_feed_property( $feed_id, 'is_active', 1 );
                $imported_feeds[] = GFAPI::get_feed( $feed_id );
		    }

            return $imported_feeds;
	    }

        /**
	     * @param string $processor_file
	     *
	     * @return array
	     * @throws Exception
	     */
        function import_processor( string $processor_file, $wp_filesystem = null ) {
            if ( ! $wp_filesystem ) $wp_filesystem = $this->init_filesystem();

            if ( ! $wp_filesystem->exists( $processor_file ) ) {
                throw new Exception( sprintf( esc_html__('Can not read from form processor import file %1$s', 'gf-civicrm' ), esc_html( basename( $processor_file ) ) ) );
            }

            if ( check_civicrm_installation()['is_error'] ) { 
                throw new Exception( esc_html__( 'Could not initialize CiviCRM', 'gf-civicrm' ) );
            }

            $profile_name = get_rest_connection_profile();
            
            $api_params = [
                'file' => $processor_file, 
                'import_locally' => '1',
            ];
            $import = api_wrapper( $profile_name, 'FormProcessorInstance', 'import', $api_params, [ 'cache' => 0 ] ) ?? [];

            return $import;
        }

        public function display_export_status() {
            if ( get_transient('gfcv_exports_status_success') ) {
                $exports = get_transient('gfcv_exports');

                $html = '<div class="grid">';
                foreach ( $exports as $entity_type => $entity ) {
                    foreach ($entity as $type => $status) {
                        $html .= "<div class='row'><span style='padding-right:15px;'><strong>" . esc_html( $entity_type ) . "</strong></span><span>" . esc_html( $status ) . "</span></div>";
                    }
                }
                $html .= '</div>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p><p>%2$s</p>%3$s',
                    esc_html__( 'Your forms - and any related Webhook Feeds and CiviCRM Form Processors - have been exported. ', 'gf-civicrm' ),
                    esc_html__( 'The following files were successfully exported. Make sure to check for any missing export files, and for any malformed exports.', 'gf-civicrm' ),
                    $html
                );

                printf( '<div class="notice notice-success gf-notice" id="gform_import_export_status_notice">%s</div>', wp_kses_post( $message ) );
            }

            if ( get_transient('gfcv_exports_status_failure') ) {
                $exports = get_transient('gfcv_exports_failures');

                $html = '<div class="grid">';
                foreach ( $exports as $entity_type => $entity ) {
                    foreach ($entity as $type => $status) {
                        $html .= "<div class='row'><span style='padding-right:15px;'><strong>" . esc_html( $entity_type ) . "</strong></span><span>" . esc_html( $status ) . "</span></div>";
                    }
                }
                $html .= '</div>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p>%2$s',
                    esc_html__( 'The following failed to export.', 'gf-civicrm' ),
                    $html
                );

                printf( '<div class="notice notice-warning gf-notice" id="gform_import_export_status_notice">%s</div>', wp_kses_post( $message ) );
            }
        }

        public function display_import_status() {
            if ( get_transient('gfcv_imports_status_success') ) {
                $imports = get_transient('gfcv_imports');

                $html = '<div class="grid">';
                foreach ( $imports as $entity_type => $entity ) {
                    foreach ($entity as $type => $status) {
                        $html .= "<div class='row'><span style='padding-right:15px;'><strong>" . esc_html( $entity_type ) . "</strong></span><span>" . wp_kses_post( $status ) . "</span></div>";
                    }
                }
                $html .= '</div>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p><p>%2$s</p><p>%3$s</p>%4$s',
                    esc_html__( 'Your forms - and any related Webhook Feeds and CiviCRM Form Processors - have been imported.', 'gf-civicrm' ),
                    wp_kses_post( __( 'If you have imported a Form Processor, <a href="/wp-admin/admin.php?page=CiviCRM&q=civicrm%2Fmenu%2Frebuild&reset=1">clear the CiviCRM cache now</a>.', 'gf-civicrm' ) ),
                    esc_html__( 'The following were imported.', 'gf-civicrm' ),
                    $html
                );

                printf( '<div class="notice notice-success gf-notice" id="gform_import_export_status_notice">%s</div>', wp_kses_post( $message ) );
            }

            if ( get_transient('gfcv_imports_status_failure') ) {
                $imports = get_transient('gfcv_imports_failures');

                $html = '<div class="grid">';
                foreach ( $imports as $entity_type => $status ) {
                    $html .= "<div class='row'><span style='padding-right:15px;'><strong>" . esc_html( $entity_type ) . "</strong></span><span>" . esc_html( $status ) . "</span></div>";
                }
                $html .= '</div>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p>%2$s',
                    esc_html__( 'The following files failed to import.', 'gf-civicrm' ),
                    $html
                );

                printf( '<div class="notice notice-warning gf-notice" id="gform_import_export_status_notice">%s</div>', wp_kses_post( $message ) );
            }
        }
    }
}