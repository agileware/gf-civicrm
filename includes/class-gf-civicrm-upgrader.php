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
    private $plugin_uri           = '';
    private $plugin_update_uri    = '';
    private $plugin               = ''; // folder/filename.php
	private $name                 = '';
	private $slug                 = '';
	private $version              = '';
    private $author               = '';
	private $author_uri           = '';

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
            'Version'       => 'Version',
            'Author'        => 'Author',
            'AuthorURI'     => 'Author URI'
        ));

        $this->plugin_file              = $plugin_file;
        $this->plugin_uri               = $plugin_data['PluginURI'];
        $this->plugin_update_uri        = 'https://api.github.com/repos/' . GF_CIVICRM_PLUGIN_GITHUB_REPO . '/releases/latest';
        $this->plugin                   = plugin_basename( $plugin_file );
        $this->name                     = $plugin_data['PluginName'];
		$this->slug                     = basename( dirname( $plugin_file ) );
		$this->version                  = $plugin_data['Version'];
        $this->author                   = $plugin_data['Author'];
        $this->author_uri               = $plugin_data['AuthorURI'];
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

        if (!$update_info) {
            return $transient;
        }

        $latest_version = $update_info->tag_name;

        // Compare versions and add the update info if a newer version is available
        if (version_compare($current_version, $latest_version, '<')) {
            $plugin = array(
                'slug'        => $this->slug,
                'plugin'      => $this->plugin,
                'name'        => $this->name,
                'new_version' => $latest_version,
                'url'         => $update_info->html_url,
                'package'     => $update_info->zipball_url
            );

            $transient->response[$this->plugin] = (object) $plugin;
        }

        return $transient;
    }

    /**
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
        $plugin_info->name = $this->name;
        $plugin_info->slug = $this->plugin;
        $plugin_info->version = ltrim($update_info->tag_name, 'v');
        $plugin_info->author = '<a href="' . $this->author_uri . '">' . $this->author . '</a>';
        $plugin_info->homepage = $this->plugin_uri;
        $plugin_info->download_link = $update_info->zipball_url;
        $plugin_info->sections = [
            'Release Notes' => '<p>' . $update_info->body . '</p>',
        ];

		return $plugin_info;
	}

    /**
     * Get the latest release information from the GitHub repository.
     *
     * @return object|false Latest release information or false on failure.
     */
    private function get_update_info() {
        // Allow cache to be skipped
		$version_info = $this->allowCached() ? $this->get_cached_version_info() : false;

		if ( false === $version_info ) {
			$version_info = $this->get_update_info_from_remote();

			if ( ! $version_info ) {
				return false;
			}

			// This is required for your plugin to support auto-updates in WordPress 5.5.
			$version_info->plugin = $this->name;
			$version_info->id     = $this->name;
			$version_info->version = $version_info->new_version;
			$version_info->author = sprintf('<a href="%s">%s</a>', esc_url($this->author_uri), esc_html($this->author));

			$this->set_version_info_cache( $version_info );
		}

		return $version_info;
    }

    private function get_update_info_from_remote() {
        // Set up the request headers, including a User-Agent as GitHub requires this.
        $args = [
            'headers' => [
                'Authorization' => 'token ' . GITHUB_ACCESS_TOKEN_TESTING_ONLY, // Use GitHub Access Token
                'User-Agent' => $this->name . ' Plugin Request', // GitHub API requires a User-Agent header.
                'Accept' => 'application/vnd.github+json',
            ],
        ];
        $response = wp_remote_get($this->plugin_update_uri, $args);

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
        $skin = new \Automatic_Upgrader_Skin();

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

    /**
	 * Get the version info from the cache, if it exists.
	 *
	 * @param string $cache_key
	 * @return boolean|string
	 */
	public function get_cached_version_info( $cache_key = '' ) {

		if ( empty( $cache_key ) ) {
			$cache_key = $this->get_cache_key();
		}

		$cache = get_option( $cache_key );

		// Cache is expired
		if ( empty( $cache['timeout'] ) || time() > $cache['timeout'] ) {
			return false;
		}

		return $cache['value'];

	}

    /**
	 * Adds the plugin version information to the database.
	 *
	 * @param string $value
	 * @param string $cache_key
	 */
	public function set_version_info_cache( $value = '', $cache_key = '' ) {

		if ( empty( $cache_key ) ) {
			$cache_key = $this->get_cache_key();
		}

		$data = array(
			'timeout' => strtotime( '30 seconds', time() ), // TODO Give this an appropriate value after testing
			'value'   => $value,
		);

		update_option( $cache_key, $data, 'no' );
	}

    /**
	 * Gets the unique key (option name) for a plugin.
	 *
	 * @return string
	 */
	private function get_cache_key() {
		$string = $this->slug;

		return 'gfcv_vi_' . md5( $string );
	}

	private function allowCached() : bool {
		return empty($_GET['force-check']);
	}
}