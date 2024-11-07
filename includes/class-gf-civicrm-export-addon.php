<?php

namespace GFCiviCRM;

use Civi\Api4\FormProcessorInstance;
use Civi\FormProcessor\API\FormProcessor;
use Civi\FormProcessor\Exporter\ExportToJson;
use GFAddOn;
use GFAPI;
use GFExport;
use GFForms;
use GFFormsModel;

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

            add_action( 'admin_post_gf_civicrm_export', [ $this, 'export_form_and_feeds' ] );
        }

        public function export_form_and_feeds() {
            // Verify the nonce and permissions
            check_admin_referer( 'gf_export_forms', 'gf_export_forms_nonce' );
            if ( ! current_user_can( 'administrator' ) ) {
                wp_die( __( 'Unauthorized request.', 'gf-civicrm-export-addon' ), 403 );
            }

            $forms = filter_input_array(INPUT_POST,
                [
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

                // Create the directory if it doesn’t exist
                if ( ! file_exists( $export_directory ) ) {
                    mkdir( $export_directory, 0755, true );
                }

                $forms_export = GFExport::prepare_forms_for_export( [ $form ]);

                // Save the form data JSON file with prefix
                $form_file_path = "$export_directory/gravityforms-export-$directory_name.json";
                $form_json = json_encode( $forms_export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
                file_put_contents( $form_file_path, $form_json );

                // Save each webhook feed data to separate JSON files using prefix and index

                $feeds_export = [ 'version' => GFForms::$version ];

                // Additional meta from compatibility with import plugin
                $feeds_default =  [ 'migrate_feed_type' => 'official' ];

                foreach ( $feeds as $feed ) {
                    $feeds_export[] = $feed + $feeds_default;
                    $feeds_file_path = "$export_directory/gravityforms-export-feeds-$directory_name.json";
                }

                $feeds_json = json_encode( $feeds_export, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES );
                file_put_contents( $feeds_file_path, $feeds_json );

                $this->export_processors($processors, $export_directory);
            }

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
            } catch (\Exception $e) {
                // Log error if needed and return null if there is an issue
                error_log("Error fetching FormProcessor `$name`: {$e->getMessage()}");
            }

            return null;
        }

        /**
         * @param array $processors
         * @param mixed $export_directory
         * @return void
         * @throws \CRM_Core_Exception
         */
        protected function export_processors(array $processors, mixed $export_directory): void
        {
            if(!class_exists('\Civi\FormProcessor\Exporter\ExportToJson')) {
                return;
            }

            $exporter = new ExportToJson();

            foreach ( $processors as $name => $id ) try {
                $file_path = "$export_directory/form-processor-$name.json";

                $export = json_encode($exporter->export($id), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

                file_put_contents($file_path, $export);
            } catch (\Exception $e) {
                error_log("Error fetching FormProcessor `$name`: {$e->getMessage()}");
            }
        }
    }
}