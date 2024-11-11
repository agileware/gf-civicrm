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
use Gravity_Forms\Gravity_Forms\Config\GF_Config;

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

        public function scripts() {
            $scripts = [
                [
                    'handle' => 'gf_civicrm_export_addon',
                    'src' => $this->get_base_url(__DIR__ ) . '/js/gf-civicrm-export-addon.js',
                    'version' => $this->_version,
                    'deps' => [ 'wp-i18n' ],
                    'enqueue' => [
                        [ $this, 'should_enqueue_scripts' ]
                    ],
                    'strings' => [
                        'action' => admin_url( 'admin-post.php?action=gf_civicrm_export' ),
                    ],
                ]
            ];

            return array_merge(parent::scripts(), $scripts);
        }

        public function should_enqueue_scripts() {
            return is_admin() && rgget( 'page' ) == 'gf_export' && rgget( 'subview' ) == 'export_form';
        }

        public function init_admin()
        {
            parent::init_admin();

            add_action('admin_post_gf_civicrm_export', [ $this, 'export_form_and_feeds' ]);

            add_action('gform_export_page_import_server', [ $this, 'import_form_server_html' ]);

            if ( isset( $_GET[ 'gf_export_status' ] ) ) {
                $status = sanitize_text_field( $_GET[ 'gf_export_status' ] );

                add_action( 'admin_notices', function() use ( $status ) {
                    $this->display_export_status( $status );
                } );
            }

            add_filter('gform_export_menu', [ self::class, 'settings_tabs' ], 10, 1);
        }
        public static function settings_tabs( $settings_tabs ) {
            if( GFCommon::current_user_can_any('gravityforms_edit_forms') ) {
                $settings_tabs[50] = [ 'name' => 'import_server', 'label' => __( 'Import from Server', 'gf-civicrm' ) ];
            }

            return $settings_tabs;
        }

        public function export_form_and_feeds() {
            // Verify the nonce and permissions
            check_admin_referer( 'gf_export_forms', 'gf_export_forms_nonce' );
            if ( ! current_user_can( 'administrator' ) ) {
                wp_die( __( 'Unauthorized request.', 'gf-civicrm-export-addon' ), 403 );
            }

            $forms = filter_input_array(INPUT_POST, [
                    'gf_form_id' => [
                        'filter' => FILTER_VALIDATE_INT,
                        'flags' => FILTER_REQUIRE_ARRAY,
                        'options' => [ 'min_range' => 0 ]
                    ]],
                false)['gf_form_id'];

            if ( empty( $forms ) ) {
                wp_die( __( 'No forms found to export.', 'gf-civicrm-export-addon' ) );
            }

            $docroot = $_SERVER['DOCUMENT_ROOT'];

            $forms = GFFormsModel::get_form_meta_by_id( $forms );

            foreach ( $forms as $form ) {
                $form_id = $form['id'];
                $feeds = GFAPI::get_feeds( form_ids: [ $form_id ] );

                if( $feeds instanceof \WP_Error ) {
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

                $action_value ??= sanitize_title($form['title'], $form_id);

                // Define the subdirectory path (either action value or form ID)
                $directory_base = 'CRM/form-processor';
                $directory_name = $action_value ?: $form['title'];
                $export_directory = apply_filters(
                    'gf-civicrm/export-directory',
                    "$docroot/$directory_base/$directory_name",
                    $docroot, $directory_base, $directory_name, $action_value, $form_id
                );
                $parent_directory = dirname($export_directory);
                $htaccess = "$parent_directory/.htaccess";

                // Create the directory if it doesn’t exist
                if ( ! file_exists( $export_directory ) ) {
                    mkdir( $export_directory, 0755, true );
                }

                // Create the htaccess if it doesn't exist. Restricts access to the exports.
                $check = file_exists( $htaccess );
                if ( ! file_exists( $htaccess ) ) {
                    $htaccess_contents = <<<HTACCESS
                    Order allow,deny
                    Deny from all
                    HTACCESS;
                    file_put_contents( $htaccess, $htaccess_contents );
                    chmod($htaccess, 0644);
                }

                $forms_export = GFExport::prepare_forms_for_export( [ $form ]);

                // Save the form data JSON file with prefix
                $form_file_path = "$export_directory/gravityforms-export-$directory_name.json";
                $form_json = json_encode( $forms_export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
                file_put_contents( $form_file_path, $form_json );
                $exports["Form"] = "gravityforms-export-$directory_name.json";

                // Save each webhook feed data to separate JSON files using prefix and index
                $feeds_export = [ 'version' => GFForms::$version ];

                // Additional meta from compatibility with import plugin
                $feeds_default =  [ 'migrate_feed_type' => 'official' ];

                foreach ( $feeds as $feed ) {
                    $feeds_export[] = $feed + $feeds_default;
                    $feeds_file_path = "$export_directory/gravityforms-export-feeds-$directory_name.json";
                    $feeds_json = json_encode( $feeds_export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES );
                    file_put_contents( $feeds_file_path, $feeds_json );

                    $exports["Feed"] = "gravityforms-export-feeds-$directory_name.json";
                }

                $exported_processors = $this->export_processors($processors, $export_directory);
                $exports = array_merge($exports,$exported_processors);
            }
            
            // Store the names of all exports for 60 seconds for status reporting
            set_transient( 'gfcv_exports', $exports, 60 );

            // Redirect back to the Export page with a success message
            wp_redirect(
                add_query_arg(
                    [
                        'gf_export_status' => 'success',
                        'page' => 'gf_export',
                        'subview' => 'export_form',
                    ],
                    admin_url( 'admin.php' )
                )
            );
            
            exit;
        }

        public function display_export_status( $status ) {
            if ( $status === 'success' ) {
                $exports = get_transient('gfcv_exports');

                $exports_html = '<table>';
                foreach ( $exports as $entity => $filename ) {
                    $exports_html .= "<tr><td style='padding-right:15px;'><strong>{$entity}</strong></td><td>{$filename}</td></tr>";
                }
                $exports_html .= '</table>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p><p>%2$s</p>%3$s',
                    esc_html__( 'Your forms - and any related Webhook Feeds and CiviCRM Form Processors - have been exported. ', 'gravityforms' ),
                    esc_html__( 'The following files were exported.', 'gravityforms' ),
                    $exports_html,
                );

                printf( '<div class="notice notice-success gf-notice" id="gform_disable_logging_notice">%s</div>', $message );
            }
            
        }

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

        private function get_form_processor( $name )
        {
            // Initialize CiviCRM if needed
            if (!function_exists('civicrm_initialize')) {
                return null;
            }

            civicrm_initialize();

            // Use APIv4 to query for the FormProcessor by name
            try {
                $result = FormProcessorInstance::get(FALSE)
                    ->addSelect('id')
                    ->addWhere('name', '=', $name)
                    ->execute();

                if ($result->count() < 1) {
                    return null;
                }

                return $result->first()['id'];
            } catch ( Exception $e) {
                // Log error if needed and return null if there is an issue
                error_log("Error fetching FormProcessor `$name`: {$e->getMessage()}");
            }

            return null;
        }

        /**
         * @param array $processors
         * @param mixed $export_directory
         * @return array
         * @throws \CRM_Core_Exception
         */
        protected function export_processors(array $processors, mixed $export_directory)
        {
            if(!class_exists('\Civi\FormProcessor\Exporter\ExportToJson')) {
                return;
            }

            $exporter = new ExportToJson();

            $exports = [];
            foreach ( $processors as $name => $id ) try {
                $file_path = "$export_directory/form-processor-$name.json";

                $export = json_encode($exporter->export($id), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

                file_put_contents($file_path, $export);
                $exports["Form Processor"] = "form-processor-$name.json";
            } catch ( Exception $e) {
                error_log("Error fetching FormProcessor `$name`: {$e->getMessage()}");
            }

            return $exports;
        }

        public function import_form_server_html() {

            if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                wp_die( __('You do not have sufficient permissions to access this page.') );
            }

            $docroot = $_SERVER['DOCUMENT_ROOT'];
            $directory_base = 'CRM/form-processor';

            $import_directory = apply_filters(
                'gf-civicrm/import-directory',
                "$docroot/$directory_base",
                $docroot, $directory_base
            );

            GFExport::page_header();

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->do_import($import_directory);
            }

            $importable_forms = $this->importable_forms($import_directory);

            ?>
            <div class="gform-settings__content">
                <form method="post" style="margin-top: 10px;" class="gform_settings_form">
                <?php wp_nonce_field('gf_civicrm_import'); ?>
                    <div class="gform-settings-panel gform-settings-panel--full">
                        <div class="gform-settings-panel__header"><h2 class="gform-settings-panel__title"><?= esc_html__('Import from Server' ) ?></h2></div>
                        <div class="gform-settings-panel__content">
                            <div class="gform-settings-description"><?= sprintf( esc_html__( 'Select the forms you would like to export from the server at “%1$s”. Note that this will overwrite all forms, feeds, and CiviCRM form processors included with the form on the filesystem.', 'gf-civicrm' ), $directory_base ); ?></div>
                            <fieldset>
                                <legend><strong><?= esc_html__('Select Forms', 'gf-civicrm') ?></strong></legend>
                                <p><?= esc_html__('These forms were detected on the filesystem:') ?></p>
                                <ul>
                                    <?php foreach($importable_forms as $key => [ 'title' => $title, 'id' => $id, 'existing' => $existing  ]) { ?>
                                        <li>
                                            <input type="checkbox" id="import-form-<?= $id ?>" name="import_form[]" value="<?= $key ?>">
                                            <label for="import-form-<?= $id ?>">
                                                <?php printf(
                                                        ($existing ? __('%1$s – will replace existing form “%2$s”', 'gf-civicrm') :'%1$s'),
                                                        $title, $existing);
                                                ?>
                                            </label>
                                        </li>
                                    <?php } ?>
                                </ul>
                            </fieldset>
                            <input class="button primary" type="submit" value="<?= __( 'Import Forms' ) ?>">
                        </div>
                    </div>
                </form>
            </div>
        <?php
        }

        protected  function importable_forms( $import_directory ): array {
            $import_files = glob( $import_directory . '/*/gravityforms-export*.json' );

            $import_files = preg_grep('{ / (?<directory_name> [^/]+) / gravityforms-export- \g{directory_name} \. json $ }xi', $import_files);

            $importable = [];

            foreach($import_files as $file) {
                $matches = [];
                preg_match('{ / gravityforms-export- ( [^/]+ ) \. json $ }xi', $file, $matches);
                [, $key] = $matches;

                $forms = json_decode(file_get_contents($file), TRUE);
                $form = reset($forms);

                if(empty($form)) {
                    continue;
                }

                $existing = GFFormsModel::get_form_meta($form['id']);

                $importable[$key] = [
                    'title' => $form['title'] ?? $key,
                    'id' => $form['id'] ?? null,
                    'existing' => (isset($existing['title']) ? $existing['title'] : null),
                ];

            }

            return $importable;
        }

        protected function do_import( $import_directory ): void {
            if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                wp_die( 'You do not have sufficient permissions to import forms.' );
            }

            check_admin_referer('gf_civicrm_import');

            $import_forms = filter_input(INPUT_POST, 'import_form', FILTER_CALLBACK, ['options' => [ $this, 'filter_form_names' ]]);
            $import_forms = array_filter($import_forms);

	        $import_directory = trailingslashit($import_directory);

            foreach($import_forms as $directory_name) {
                $form_file =  $import_directory . $directory_name . "/gravityforms-export-$directory_name.json";

                try {
	                $form_ids = $this->import_forms( $form_file );
                } catch(Throwable $e) {
                    GFCommon::add_error_message( $e->getMessage() );
                    continue;
                }

                $feeds_file = $import_directory . $directory_name . "/gravityforms-export-feeds-$directory_name.json";

                if ( file_exists( $feeds_file ) ) {
                    try {
	                    $this->import_feeds( $feeds_file, $form_ids );
                    } catch(Throwable $e) {
		                GFCommon::add_error_message( $e->getMessage() );
	                }
                }

                $processor_file = $import_directory . $directory_name . "/form-processor-$directory_name.json";

                if ( file_exists( $processor_file ) ) {
                    try {
                        $this->import_processor( $processor_file );
                    } catch(Throwable $e) {
                        GFCommon::add_error_message( $e->getMessage() );
                    }
                }
            }
        }

        public function filter_form_names( $input ) {
            // Exclude any filenames with path metacharacters or control characters
            return preg_match('{ [/:@<>] | \p{Z} }x', $input) ? NULL : $input;
        }

	    /**
	     * @param string $form_file
	     *
	     * @return void
	     * @throws Exception
	     */
	    public function import_forms( string $form_file ): array {
		    if ( ! is_readable( $form_file ) ) {
			    throw new Exception( sprintf( __( 'Can not read from form import file %1$s', 'gf-civicrm' ), $form_file ) );
		    }

            $ids = [];

		    // Import the Form meta. Lifted from GFExport class, but update a form if possible

		    $form_json = file_get_contents( $form_file );
		    $form_json = GFExport::sanitize_forms_json( $form_json );

		    $forms = json_decode( $form_json, true );

		    $version = $forms['version'] ?? null;

		    unset( $forms['version'] );

		    if ( empty( $forms ) ) {
			    throw new Exception( sprintf( __( 'No forms found in input file %1$s', 'gf-civicrm' ), $form_file ) );
            }

		    foreach ( $forms as $form ) {
			    $form['markupVersion'] = rgar( $form, 'markupVersion' ) ? $form['markupVersion'] : 2;

			    $form = GFFormsModel::convert_field_objects( $form );
			    $form = GFFormsModel::sanitize_settings( $form );

			    $id = null;

			    if ( ! empty( $form['id'] ) ) {
				    $id = $form['id'];
				    GFAPI::update_form( $form );
			    } else {
				    $id = GFAPI::add_form( $form );
			    }

                $ids[] = $id;
		    }

            return $ids;
	    }

	    /**
	     * @param string $feeds_file
	     * @param string $form_file
	     *
	     * @return void
	     * @throws Exception
	     */
	    public function import_feeds( string $feeds_file, array $form_ids ): array {
		    if ( ! is_readable( $feeds_file ) ) {
			    throw new Exception( sprintf( __( 'Can not read from feeds import file %1$s', 'gf-civicrm' ), $feeds_file ) );
		    }

		    $feeds_json = file_get_contents( $feeds_file );
		    $feeds_json = GFExport::sanitize_forms_json( $feeds_json );

		    $feeds = json_decode( $feeds_json, true );

		    $feeds_version = $feeds['version'] ?? null;
		    unset( $feeds['version'] );

		    global $wpdb;
		    $imported_feeds = [];

		    $table = $wpdb->prefix . 'gf_addon_feed';

		    foreach ( $feeds as $idx => $feed ) {
                if ( empty($feed['form_id'])
                     || empty($feed['addon_slug'])
                     || empty($feed['meta'])
                     || array_search($feed['form_id'], $form_ids) === false ) {
                    GFCommon::add_error_message( sprintf ( __( 'Incorrect data loading feed %1$d from %2$s' ), $idx, $feeds_file ) );
                    continue;
                }

                $query = $wpdb->prepare( 'SELECT 1 FROM ' . $table . ' WHERE id = %d AND form_id = %d', $feed['id'], $feed['form_id'] );

                if( is_null ( $wpdb->get_var( $query ) ) ) {
                    // If the feed does not belong to a form in the same directory, import it as a new feed.
                    $feed['id'] = null;
                }

                $imported_feeds[] = $wpdb->replace(
                    $table,
                    [
	                    'id'         => $feed['id'],
	                    'form_id'    => $feed['form_id'],
	                    'is_active'  => $feed['is_active'] ?? 0,
	                    'meta'       => json_encode( $feed['meta'] ),
	                    'addon_slug' => $feed['addon_slug'],
	                    'feed_order' => $feed['feed_order'] ?? null,
                    ],
                    [
                        '%d', // id
                        '%d', // form_id,
                        '%d', // is_active,
                        '%s', // meta,
                        '%s', // addon_slug,
                        '%d', // feed_order,
                    ]
                );
		    }

            return $imported_feeds;
	    }

        function import_processor( string $processor_file ) {
            if ( ! is_readable( $processor_file ) ) {
                throw new Exception( sprintf( __('Can not read from form processor import file %1$s', 'gf-civicrm' ), $processor_file ) );
            }

            if( ! civicrm_initialize() ) {
                throw new Exception( __( 'Could not initialize CiviCRM' ) );
            }

            return civicrm_api3('FormProcessorInstance', 'import', [ 'file' => $processor_file, 'import_locally' => '1' ]);
        }
    }
}