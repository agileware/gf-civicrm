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
            return is_admin() && rgget( 'page' ) == 'gf_export' && rgget( 'subview' ) == 'import_server';
        }

        public function init_admin()
        {
            parent::init_admin();

            add_action('admin_post_gf_civicrm_export', [ $this, 'export_form_and_feeds' ]);

            add_action('gform_export_page_export_gfcivicrm', [ $this, 'export_gfcivicrm_form_html' ]);
            add_action('gform_export_page_import_server', [ $this, 'import_form_server_html' ]);

            add_action( 'admin_notices', function() {
                if ( isset($_GET['subview']) && $_GET['subview'] === 'export_gfcivicrm' ) {
                    $this->display_export_status();
                }
            } );

            add_action( 'admin_notices', function() {
                if ( isset($_GET['subview']) && $_GET['subview'] === 'import_server' ) {
                    $this->display_import_status();
                }
            } );

            add_filter('gform_export_menu', [ self::class, 'settings_tabs' ], 10, 1);
        }

        public static function settings_tabs( $settings_tabs ) {
            if( GFCommon::current_user_can_any('gravityforms_edit_forms') ) {
                $settings_tabs[25] = [ 'name' => 'export_gfcivicrm', 'label' => __( 'Export GF CiviCRM', 'gf-civicrm' ) ];
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

            $exports = [];
            $failures = [];
            foreach ( $forms as $form ) {
                $form_id = $form['id'];
                $feeds = GFAPI::get_feeds( form_ids: [ $form_id ] );

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
                $directory_base = 'CRM/gf-civicrm-exports';
                $fp_directory = 'form-processors';
                $directory_name = $form_slug;
                $export_directory = apply_filters(
                    'gf-civicrm/export-directory',
                    "$docroot/$directory_base/$directory_name",
                    $docroot, $directory_base, $directory_name, $action_value, $form_slug, $form_id
                );
                $fp_export_directory = apply_filters(
                    'gf-civicrm/fp-export-directory',
                    "$docroot/$directory_base/$fp_directory",
                    $docroot, $directory_base, $directory_name, $fp_directory, $action_value, $form_slug, $form_id
                );

                // Generate the directories and protect with htaccess
                foreach ( [$export_directory, $fp_export_directory] as $directory ) {
                    $parent_directory = dirname($directory);
                    $htaccess = "$parent_directory/.htaccess";

                    // Create the directory if it doesn’t exist
                    if ( ! file_exists( $directory ) ) {
                        mkdir( $directory, 0755, true );
                    }

                    // Create the htaccess if it doesn't exist. Restricts access to the exports.
                    if ( ! file_exists( $htaccess ) ) {
                        $htaccess_contents = <<<HTACCESS
                        Order allow,deny
                        Deny from all
                        HTACCESS;
                        file_put_contents( $htaccess, $htaccess_contents );
                        chmod($htaccess, 0644);
                    }
                }

                // Save each webhook feed data to separate JSON files using prefix and index
                $feeds_export = [ 'version' => GFForms::$version ];

                // Additional meta from compatibility with import plugin
                $feeds_default =  [ 'migrate_feed_type' => 'official' ];

                foreach ( $feeds as $feed ) {
                    $feeds_export[] = $feed + $feeds_default;
                    $feeds_file_name = "feeds--$directory_name.json";
                    $feeds_file_path = "$export_directory/$feeds_file_name";
                    $feeds_json = json_encode( $feeds_export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES );
                    $status = file_put_contents( $feeds_file_path, $feeds_json );
                    if ( !$status ) {
                        $failures['Feed'][$feed['id']] = $feed['meta']['feedName'];
                    } else {
                        $exports['Feed'][$feed['id']] = $feeds_file_name;
                    }

                    $form['gf-civicrm-export-webhook-feeds'][] = $feed['meta']['feedName'];
                }

                $exported_processors = $this->export_processors($processors, $fp_export_directory);
                foreach ( $exported_processors as $status => $processors ) {
                    foreach ( $processors as $id => $processor ) {
                        if ( $status === 'success' ) {
                            $exports["Form Processor"][$id] = $processor;
                        }
                        else {
                            $failures["Form Processor"][$id] = $id;
                        }
                        $form['gf-civicrm-export-form-processors'][] = $id;
                    }
                }

                // Save the form data JSON file
                $forms_export = GFExport::prepare_forms_for_export( [ $form ]);

                $form_file_name = "form--$directory_name.json";
                $form_file_path = "$export_directory/$form_file_name";
                $form_json = json_encode( $forms_export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
                $status = file_put_contents( $form_file_path, $form_json );
                if ( !$status ) {
                    $failures['Form'][$form['id']] = $form['title'];
                } else {
                    $exports['Form'][$form['id']] = $form_file_name;
                }
            }

            // Store the names of exports for 60 seconds for status reporting
            // Do it this way so we can report on both successes and failures
            if ( !empty($failures) ) {
                set_transient( 'gfcv_exports_failures', $failures, 60 );
                set_transient( 'gfcv_exports_status_failure', true, 60 );
            }
            if (!empty($exports) ){
                set_transient( 'gfcv_exports', $exports, 60 );
                set_transient( 'gfcv_exports_status_success', true, 60 );
            }

            // Redirect back to the Export page with a success message
            wp_redirect(
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
                GFCommon::log_debug( __METHOD__ . "(): GF CiviCRM Errors => Error fetching FormProcessor `$name`: " . $e->getMessage() );
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
                $file_path = "$export_directory/$name.json";

                $export = json_encode($exporter->export($id), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

                file_put_contents($file_path, $export);
                $exports['success'][$name] = "$name.json";
            } catch ( Exception $e) {
                GFCommon::log_debug( __METHOD__ . "(): GF CiviCRM Export Errors => Error exporting FormProcessor `$name`: " . $e->getMessage() );
                $exports['failure'][$name] = $name;
            }

            return $exports;
        }

        /**
         * Replicate the Gravity Forms Export Forms subview. Add in our modifications.
         */
        public function export_gfcivicrm_form_html() {

            if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                wp_die( __('You do not have sufficient permissions to access this page.') );
            }

            $docroot = $_SERVER['DOCUMENT_ROOT'];
            $directory_base = 'CRM/form-processor';

            $export_directory = apply_filters(
                'gf-civicrm/import-directory',
                "$docroot/$directory_base",
                $docroot, $directory_base
            );

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
                        <header class="gform-settings-panel__header"><legend class="gform-settings-panel__title"><?php esc_html_e( 'Export CiviCRM Integrated Forms', 'gravityforms' )?></legend></header>
                        <div class="gform-settings-panel__content">
                            <div class="gform-settings-description">
                                <?php echo sprintf( esc_html__( 'Select the forms you would like to export from the server to “%1$s”. Associated webhook feeds and CiviCRM form processors will also be exported. Note that this will overwrite all forms, feeds, and CiviCRM form processors included with the form exports already on the filesystem.', 'gravityforms' ), $directory_base ); ?>
                            </div>
                            <table class="form-table">
                                <tr valign="top">
                                    <th scope="row">
                                        <label for="export_fields"><?php esc_html_e( 'Select Forms', 'gravityforms' ); ?></label> <?php gform_tooltip( 'export_select_forms' ) ?>
                                    </th>
                                    <td>
                                        <ul id="export_form_list">
                                            <li>
                                                <input type="checkbox" id="gf_export_forms_all" />
                                                <label for="gf_export_forms_all" data-deselect="<?php esc_attr_e( 'Deselect All', 'gravityforms' ); ?>" data-select="<?php esc_attr_e( 'Select All', 'gravityforms' ); ?>"><?php esc_html_e( 'Select All', 'gravityforms' ); ?></label>
                                            </li>
                                            <?php
                                            $forms = RGFormsModel::get_forms( null, 'title' );
    
                                            /**
                                             * Modify list of forms available for export.
                                             *
                                             * @since 2.4.7
                                             *
                                             * @param array $forms Forms to display on Export Forms page.
                                             */
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
                            <button class="button primary" formaction="/wp-admin/admin-post.php?action=gf_civicrm_export">Export Form &amp; Feeds to Server</button>
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
        public function import_form_server_html() {

            if ( ! GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ) {
                wp_die( __('You do not have sufficient permissions to access this page.') );
            }

            $docroot = $_SERVER['DOCUMENT_ROOT'];
            $directory_base = 'CRM/gf-civicrm-exports';

            $import_directory = apply_filters(
                'gf-civicrm/import-directory',
                "$docroot/$directory_base",
                $docroot, $directory_base
            );

            GFExport::page_header();

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->do_import($import_directory);
            }

            $forms = RGFormsModel::get_forms( null, 'title' );
    
            /**
             * Modify list of forms available for export.
             *
             * @since 2.4.7
             *
             * @param array $forms Forms to display on Export Forms page.
             */
            $forms = apply_filters( 'gform_export_forms_forms', $forms );

            $select_forms = '<option value="create">-- Create new form --</option>'; // Placeholder option.
            foreach ( $forms as $form ) {
                $title_value = sanitize_title( $form->title );
                $title_value = str_replace( '-', '_', $title_value ); // Replace dashes with underscores
                $select_forms .= '<option value="' . $title_value . '">' . esc_html( $form->title ) . '</option>';
            }

            // Check for any existing form processors with this name
            $form_processors = \Civi\Api4\FormProcessorInstance::get(FALSE)
                ->addSelect('id', 'name', 'title')
                ->execute();
            $select_form_processors = '<option value="create">Create new form processor</option>'; // Placeholder option.
            foreach ( $form_processors as $processor ) {
                $select_form_processors .= '<option value="' . $processor['name'] . '">' . esc_html( $processor['title'] ) . '</option>';
            }
            

            $importable_forms = $this->importable_forms($import_directory);
            $importable_form_processors = $this->importable_form_processors($import_directory);

            ?>
            <div class="gform-settings__content">
                <form method="post" style="margin-top: 10px;" class="gform_settings_form">
                <?php wp_nonce_field('gf_civicrm_import'); ?>
                    <div class="gform-settings-panel gform-settings-panel--full">
                        <div class="gform-settings-panel__header"><h2 class="gform-settings-panel__title"><?= esc_html__('Import from Server' ) ?></h2></div>
                        <div class="gform-settings-panel__content">
                            <div class="gform-settings-description"><?= sprintf( esc_html__( 'Select the forms you would like to import from the server at “%1$s”. Note that this will overwrite the current database entries for all existing forms, feeds, and CiviCRM form processors included with the form export files.', 'gf-civicrm' ), $directory_base ); ?></div>
                            <fieldset>
                                <legend><h3><?= esc_html__('Select Forms', 'gf-civicrm') ?></h3></legend>
                                <p><?= esc_html__('These forms were detected on the filesystem:') ?></p>
                                <ul id="import_form_list">
                                    <?php foreach($importable_forms as $key => $values) { 
                                        $feeds = implode(", ", $values['feeds']);
                                        $sanitized_feeds = implode(", ", array_map('sanitize_text_field', $values['feeds']));
                                        $form_processors = implode(", ", $values['form_processors']);
                                        ?>
                                        <li>
                                            <div>
                                                <input type="checkbox" id="import-form-<?= $values['id'] ?>" name="import_form[<?= $key ?>]" value="<?= $key ?>" data-formprocessors="<?= $form_processors ?>" data-feeds="<?= $sanitized_feeds ?>">
                                                <label for="import-form-<?= $values['id'] ?>">
                                                    <?php 
                                                    printf(
                                                            ($values['existing'] ? __('<strong>%1$s</strong> - Includes feeds: %2$s', 'gf-civicrm') :'%1$s'),
                                                            $values['title'], $feeds);
                                                    ?>
                                                </label>
                                            </div>
                                            <div class="align-right">
                                                <label for="import-form-into-<?= $values['id'] ?>">
                                                    <?php echo __('Replace form', 'gf-civicrm'); ?>
                                                </label>
                                                <select name="import_form_into[<?= $key ?>]" id="import-form-into-<?= $values['id'] ?>">
                                                    <?php echo $select_forms; ?>
                                                </select>
                                            </div>
                                        </li>
                                    <?php } ?>
                                </ul>
                            </fieldset>
                            <fieldset>
                                <legend><h3><?= esc_html__('Select Form Processors', 'gf-civicrm') ?></h3></legend>
                                <p><?= esc_html__('These form processors were detected on the filesystem:') ?></p>
                                <ul id="import_form_processors_list">
                                    <?php foreach($importable_form_processors as $key => $values) { 
                                        ?>
                                        <li>
                                            <div>
                                                <input type="checkbox" id="import-form-processor-<?= $key ?>" name="import_form_processor[]" value="<?= $key ?>">
                                                <label for="import-form-processor-<?= $key ?>">
                                                    <?php 
                                                    printf(
                                                            __('<strong>%1$s</strong> - Will replace the existing form processor <strong>%2$s</strong> (ID: %3$s) on import.', 'gf-civicrm'),
                                                            $values['title'], $values['existing_name'], $values['existing_id']);
                                                    ?>
                                                </label>
                                            </div>
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

        /**
         * Check the filesystem on the server for import files in our designated directory.
         */
        protected  function importable_forms( $import_directory ): array {
            $import_files = glob( $import_directory . '/*/*.json' );

            $import_files = preg_grep('{ / (?<directory_name> [^/]+) / form-- \g{directory_name} \. json $ }xi', $import_files);

            $importable = [];

            foreach($import_files as $file) {
                $matches = [];
                // Grab the key from the file name
                preg_match('{ / form-- ( [^/]+ ) \. json $ }xi', $file, $matches);
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
                    'feeds' => $form['gf-civicrm-export-webhook-feeds'],
                    'form_processors' => $form['gf-civicrm-export-form-processors'],
                ];

            }

            return $importable;
        }

        /**
         * Check the filesystem on the server for Form Processor import files in our designated directory.
         */
        protected  function importable_form_processors( $import_directory ): array {
            $import_files = glob( $import_directory . '/form-processors/*.json' );

            $import_files = preg_grep('{ / (?<directory_name> [^/]+) / *. json $ }xi', $import_files);

            $importable = [];

            foreach($import_files as $file) {
                $matches = [];
                // Grab the key from the file name
                preg_match('{ / ( [^/]+ ) \. json $ }xi', $file, $matches);
                [, $key] = $matches;

                $processor = json_decode(file_get_contents($file), TRUE);

                if(empty($processor)) {
                    continue;
                }

                // Check for any existing form processor with this name
                $existing = \Civi\Api4\FormProcessorInstance::get(FALSE)
                    ->addSelect('id', 'name', 'title')
                    ->addWhere('name', '=', $key)
                    ->execute()
                    ->first();

                $importable[$key] = [
                    'title' => $processor['title'] ?? $key,
                    'id' => $processor['id'] ?? null,
                    'existing' => (isset($existing['title']) ? $existing['title'] : null),
                    'existing_name' => (isset($existing['name']) ? $existing['name'] : null),
                    'existing_id' => (isset($existing['id']) ? $existing['id'] : null),
                ];

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
                wp_die( 'You do not have sufficient permissions to import forms.' );
            }

            check_admin_referer('gf_civicrm_import');

            $import_forms = filter_input(INPUT_POST, 'import_form', FILTER_CALLBACK, ['options' => [ $this, 'filter_form_names' ]]);
            $import_form_processors = filter_input(INPUT_POST, 'import_form_processor', FILTER_CALLBACK, ['options' => [ $this, 'filter_form_names' ]]);
            if ( !$import_forms && !$import_form_processors ) {
                echo 'No imports selected'; // TODO make this a proper message
                return; // No forms selected for import
            }
            $import_forms = $import_forms ? array_filter($import_forms) : null;
            $import_form_processors = $import_form_processors ? array_filter($import_form_processors) : null;

	        $import_directory = trailingslashit($import_directory);

            $imports = [];
            $failures = [];

            // Handle importing selected forms and their associated feeds.
            foreach ( $import_forms as $directory_name ) {
                $form_file_name = "form--$directory_name.json";
                $form_file =  $import_directory . $directory_name . "/" . $form_file_name;

                try {
                    // Get the import target for this form
                    $import_target = isset($_POST['import_form_into'][$directory_name]) ? $_POST['import_form_into'][$directory_name] : '';
	                $form_id = $this->import_form( $form_file, $import_target );
                    
                    $form = GFAPI::get_form( $form_id );
                    $imports['Form'][$form_id] = $form['title'] . ' - source: ' . $form_file_name;
                } catch ( Throwable $e ) {
                    $failures['Form'] = 'Failed to import Form => ' . $e->getMessage();
                    GFCommon::log_debug( __METHOD__ . '(): GF CiviCRM Import Errors => ' . $e->getMessage() );
                }

                $feeds_file = $import_directory . $directory_name . "/feeds--$directory_name.json";

                // If we don't have a form ID, there's nothing to import feeds into, so we skip.
                if ( file_exists( $feeds_file ) && $form_id ) {
                    try {
	                    $feeds = $this->import_feeds( $feeds_file, $form_id );

                        foreach ($feeds as $feed) {
                            $form = GFAPI::get_form( $feed['form_id'] );
                            $imports['Feed'][$feed['id']] = sprintf('%1$s for the form %2$s', $feed['meta']['feedName'], $form['title']);
                        }
                    } catch ( Throwable $e ) {
                        $failures['Feed'] = $e->getMessage();
                        GFCommon::log_debug( __METHOD__ . '(): GF CiviCRM Import Errors => ' . $e->getMessage() );
	                }
                }
            }

            // Handle importing form processors
            foreach ( $import_form_processors as $processor_name ) {
                $processor_file = $import_directory . "/form-processors/$processor_name.json";

                if ( !file_exists( $processor_file ) ) {
                    continue;
                }

                try {
                    $form_processor = $this->import_processor( $processor_file );
                    $fp_instance = \Civi\Api4\FormProcessorInstance::get(FALSE)
                        ->addWhere('id', '=', $form_processor['import']['new_id']) // the imported id may be different to the original id in the import file
                        ->execute()
                        ->first();

                    $imports["Form Processor"][$fp_instance['id']] = sprintf('%1$s - %2$s', $fp_instance['title'], $fp_instance['name']);
                } catch ( Throwable $e ) {
                    $failures["Form Processor"] = $e->getMessage();
                    GFCommon::log_debug( __METHOD__ . '(): GF CiviCRM Import Errors => ' . $e->getMessage() );
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
                        'subview' => 'import_server',
                    ],
                    admin_url( 'admin.php' )
                )
            );
        }

        public function filter_form_names( $input ) {
            // Exclude any filenames with path metacharacters or control characters
            return preg_match('{ [/:@<>] | \p{Z} }x', $input) ? NULL : $input;
        }

	    /**
         * Import the Form meta. Lifted from GFExport class, but update a form if possible
         * 
	     * @param string $form_file
	     *
	     * @return int
	     * @throws Exception
	     */
	    public function import_form( string $form_file, string $import_target = '' ) {
		    if ( ! is_readable( $form_file ) ) {
			    throw new Exception( sprintf( __( 'Can not read from form import file %1$s', 'gf-civicrm' ), basename( $form_file ) ) );
		    }

		    $form_json = file_get_contents( $form_file );
		    $form_json = GFExport::sanitize_forms_json( $form_json );

		    $import_form_raw = json_decode( $form_json, true );

		    if ( empty( $import_form_raw ) ) {
			    throw new Exception( sprintf( __( 'No forms found in input file %1$s', 'gf-civicrm' ), basename( $form_file ) ) );
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

            $title_slug = sanitize_title($import_form['title']);
            $title_slug = str_replace( '-', '_', $title_slug ); // Replace dashes with underscores

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
                throw new Exception( sprintf( '%1$s : %2$s', basename( $form_file ), $status->get_error_message() ) );
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
	    public function import_feeds( string $feeds_file, int $form_id ): array {
		    if ( ! is_readable( $feeds_file ) ) {
			    throw new Exception( sprintf( __( 'Can not read from feeds import file %1$s. Aborting feed imports.', 'gf-civicrm' ), basename( $feeds_file ) ) );
		    }

		    $feeds_json = file_get_contents( $feeds_file );
		    $feeds_json = GFExport::sanitize_forms_json( $feeds_json );

		    $import_feeds = json_decode( $feeds_json, true );

            // Unset the version from the array so we don't loop through it
		    unset( $import_feeds['version'] );

		    global $wpdb;
		    $imported_feeds = [];

            // Get all feeds for the given form_id
            $current_form_feeds = GFAPI::get_feeds( null, $form_id );

            /**
             * Deactivate the existing feeds. If they're updated, they will be reactivated.
             * 
             * If there is a failure during import below, these existing feeds will still be deactivated.
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
                if ( empty($feed['form_id'])
                     || empty($feed['addon_slug'])
                     || empty($feed['meta']) ) {
                    GFCommon::add_error_message( sprintf ( __( 'Incorrect data loading feed id %1$d from %2$s. Aborting feed imports.' ), $feed['id'], basename( $feeds_file ) ) );
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
                    throw new Exception( sprintf( '%1$s : %2$s. Please check your feeds on this form.', basename( $feeds_file ), $status->get_error_message() ) );
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
        function import_processor( string $processor_file ) {
            if ( ! is_readable( $processor_file ) ) {
                throw new Exception( sprintf( __('Can not read from form processor import file %1$s', 'gf-civicrm' ), basename( $processor_file ) ) );
            }

            if( ! civicrm_initialize() ) {
                throw new Exception( __( 'Could not initialize CiviCRM' ) );
            }

            return civicrm_api3('FormProcessorInstance', 'import', [ 'file' => $processor_file, 'import_locally' => '1' ]);
        }

        public function display_export_status() {
            if ( get_transient('gfcv_exports_status_success') ) {
                $exports = get_transient('gfcv_exports');

                $html = '<table>';
                foreach ( $exports as $entity_type => $entity ) {
                    foreach ($entity as $type => $filename) {
                        $html .= "<tr><td style='padding-right:15px;'><strong>{$entity_type}</strong></td><td>{$filename}</td></tr>";
                    }
                }
                $html .= '</table>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p><p>%2$s</p>%3$s<p>%4$s</p>',
                    esc_html__( 'Your forms - and any related Webhook Feeds and CiviCRM Form Processors - have been exported. ', 'gravityforms' ),
                    esc_html__( 'The following files were successfully exported.', 'gravityforms' ),
                    $html,
                    esc_html__( 'Make sure to check for any missing export files, and for any malformed exports.', 'gravityforms' ),
                );

                printf( '<div class="notice notice-success gf-notice" id="gform_disable_logging_notice">%s</div>', $message );
            }

            if ( get_transient('gfcv_exports_status_failure') ) {
                $exports = get_transient('gfcv_exports_failures');

                $html = '<table>';
                foreach ( $exports as $entity_type => $entity ) {
                    foreach ($entity as $type => $filename) {
                        $html .= "<tr><td style='padding-right:15px;'><strong>{$entity_type}</strong></td><td>{$filename}</td></tr>";
                    }
                }
                $html .= '</table>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p>%2$s',
                    esc_html__( 'The following failed to export.', 'gravityforms' ),
                    $html,
                );

                printf( '<div class="notice notice-warning gf-notice" id="gform_disable_logging_notice">%s</div>', $message );
            }
            
        }

        public function display_import_status() {
            if ( get_transient('gfcv_imports_status_success') ) {
                $imports = get_transient('gfcv_imports');

                $html = '<table>';
                foreach ( $imports as $entity_type => $entity ) {
                    foreach ($entity as $type => $filename) {
                        $html .= "<tr><td style='padding-right:15px;'><strong>{$entity_type}</strong></td><td>{$filename}</td></tr>";
                    }
                }
                $html .= '</table>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p><p>%2$s</p>%3$s',
                    esc_html__( 'Your forms - and any related Webhook Feeds and CiviCRM Form Processors - have been imported. ', 'gravityforms' ),
                    esc_html__( 'The following were imported.', 'gravityforms' ),
                    $html,
                );

                printf( '<div class="notice notice-success gf-notice" id="gform_disable_logging_notice">%s</div>', $message );
            }

            if ( get_transient('gfcv_imports_status_failure') ) {
                $imports = get_transient('gfcv_imports_failures');

                $html = '<table>';
                foreach ( $imports as $entity_type => $message ) {
                    $html .= "<tr><td style='padding-right:15px;'><strong>{$entity_type}</strong></td><td>{$message}</td></tr>";
                }
                $html .= '</table>';

                $message = sprintf(
                    '<p><strong>%1$s</strong></p>%2$s',
                    esc_html__( 'The following files failed to import.', 'gravityforms' ),
                    $html,
                );

                printf( '<div class="notice notice-warning gf-notice" id="gform_disable_logging_notice">%s</div>', $message );
            }
        }
    }
}