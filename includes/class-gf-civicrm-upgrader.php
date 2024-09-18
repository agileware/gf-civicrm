<?php
namespace GFCiviCRM;

if (!class_exists('WP_Upgrader')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

// Add this code to manually trigger the check and inspect the transient
add_action('admin_init', function() {
    // Delete the transient to ensure a new update check
    delete_site_transient('update_plugins');

    // Trigger the update check
    wp_update_plugins();

    // Retrieve and log the update_plugins transient
    $update_plugins = get_site_transient('update_plugins');
    
    if ($update_plugins) {
        error_log('Update Plugins Transient Content: ' . print_r($update_plugins, true));
    } else {
        error_log('Update Plugins Transient not set.');
    }
});

/**
 * Class My_Plugin_Updater
 * Handles the update process for the plugin using WP_Upgrader.
 */
class Upgrader extends \Plugin_Upgrader {

    private $plugin_file          = '';
    private $plugin_update_uri    = '';
    private $plugin               = ''; // folder/filename.php
	private $name                 = '';
	private $slug                 = '';
	private $version              = '';

    /**
     * Constructor.
     *
     * @param string $plugin_file The main plugin file.
     */
    public function __construct($plugin_file) {
        // Get plugin information
        $plugin_data = get_file_data($plugin_file, array(
            'PluginName'    => 'Plugin Name',
            'PluginURI'     => 'Plugin URI',
            'Version'       => 'Version'
        ));

        $this->plugin_file              = $plugin_file;
        $this->plugin_update_uri        = $plugin_data['PluginURI'] . '/releases/latest';
        $this->plugin                   = plugin_basename( $plugin_file );
        $this->name                     = $plugin_data['PluginName'];
		$this->slug                     = basename( dirname( $plugin_file ) );
		$this->version                  = $plugin_data['Version'];
    }

    /**
     * Initialize the updater by hooking into WordPress.
     */
    public function init() {
        add_filter( 'pre_set_site_transient_update_plugins', array($this, 'check_for_update') );
        add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
    }

    /**
     * Check for plugin updates.
     *
     * @param object $transient The current update transient.
     * @return object Modified update transient with potential update data.
     */
    public function check_for_update($transient) {
        // Log the transient data to observe what happens
        error_log(print_r($transient, true));

        if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

        if ( ! empty( $transient->response ) && ! empty( $transient->response[ $this->name ] ) ) {
			return $transient;
		}

        // Get the current version of the plugin
        $current_version = $this->version;

        // Get the latest version information from GitHub
        $update_info = $this->get_update_info();

        /*if (!$update_info) {
            return $transient;
        }

        $latest_version = $update_info->tag_name;*/

        $latest_version = '1.9.1';
        // Compare versions and add the update info if a newer version is available
        if (version_compare($current_version, $latest_version, '<')) {
            $plugin = array(
                'slug'        => $this->slug,
                'plugin'      => $this->plugin, // plugin_basename
                'name'        => $this->name,
                'new_version' => $latest_version,
                'url'         => 'https://github.com/agileware/gf-civicrm', // TODO hardcoding because of rate limits $update_info->html_url,
                'package'     => 'https://github.com/agileware/gf-civicrm/archive/refs/tags/1.9.1.zip' // TODO hardcoding because of rate limits $update_info->zipball_url,
            );

            $transient->response[$this->plugin] = (object) $plugin;
        }

        return $transient;
    }

    /**
	 * TODO : CHANGEME
     * Updates information on the "View version x.x details" page with custom data.
	 *
	 * @uses api_request()
	 *
	 * @param mixed   $_data
	 * @param string  $_action
	 * @param object  $_args
	 * @return object $_data
	 */
	public function plugins_api_filter( $result, $action = '', $args = null ) {
        $update_plugins = get_site_transient('update_plugins');
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || ( $args->slug !== $this->slug ) ) {
			return $result;
		}

        // Get the latest version information from GitHub
        $update_info = $this->get_update_info();

        if (!$update_info) {
            return $result;
        }

        $plugin_info = new \stdClass();
        $plugin_info->name = 'My Plugin';
        $plugin_info->slug = 'your-plugin-slug';
        $plugin_info->version = ltrim($update_info->tag_name, 'v');
        $plugin_info->author = '<a href="https://yourwebsite.com">Your Name</a>';
        $plugin_info->homepage = 'https://github.com/yourusername/your-plugin-repo';
        $plugin_info->download_link = $update_info->zipball_url;
        $plugin_info->requires = '5.0'; // Set required WP version
        $plugin_info->tested = '6.3'; // Set the latest tested WP version
        $plugin_info->sections = [
            'description' => '<p>' . $update_info->body . '</p>',
            'changelog' => '<p>' . nl2br($update_info->body) . '</p>',
        ];


        /*
		$api_response = $this->get_repo_api_data();

		if ( !empty( $api_response ) ){
			$_data = $api_response;
		}*/

		return $plugin_info;
	}

    /**
     * Get the latest release information from the GitHub repository.
     *
     * @return object|false Latest release information or false on failure.
     */
    private function get_update_info() {
        $url = "https://api.github.com/repos/agileware/gf-civicrm/releases/latest";

        // Set up the request headers, including a User-Agent as GitHub requires this.
        $args = [
            'headers' => [
                'User-Agent' => 'WordPress Plugin Request', // GitHub API requires a User-Agent header.
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ];
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data) || isset($data->message)) {
            return false;
        }

        return $data;
    }

    /**
     * Perform the plugin update.
     *
     * @param string $package_url URL of the update package.
     */
    public function run_updater($package_url) {
        // Set up the upgrader skin
        $skin = new Automatic_Upgrader_Skin();

        // Use the inherited install method from WP_Upgrader
        $this->init();
        $this->install($package_url);
        
        // Check if the plugin was upgraded correctly
        if (is_wp_error($this->result)) {
            error_log($this->result->get_error_message());
        } else {
            error_log('Plugin updated successfully.');
        }
    }
}