<?php
namespace GFCiviCRM;

use GFAPI;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

if (!class_exists('WP_Upgrader')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

/**
 * Handles the update process for the plugin using WP_Upgrader.
 */
class Upgrader extends \Plugin_Upgrader {

    private static $_instance = NULL;

    private $plugin_uri           = '';
    private $plugin_update_uri    = '';
    private $plugin               = ''; // folder/filename.php
	private $name                 = '';
	private $slug                 = '';
	private $version              = '';
    private $author               = '';
	private $author_uri           = '';

    public static function get_instance() {
        return self::$_instance;
    }

    /**
     * Constructor.
     *
     * @param string $plugin_file The main plugin file.
     */
    public function __construct( $plugin_file ) {
        // Get plugin information
        $plugin_data = get_file_data( $plugin_file, [
            'PluginName'    => 'Plugin Name',
            'PluginURI'     => 'Plugin URI',
            'Version'       => 'Version',
            'Author'        => 'Author',
            'AuthorURI'     => 'Author URI'
        ] );

        // Get plugin Update URI
        $settings = get_option( 'gravityformsaddon_gf-civicrm_settings' );
        $enable_prereleases = is_array($settings) && ($settings['enable_prereleases'] ?? FALSE);
        if ( $enable_prereleases ) {
            $plugin_update_uri = 'https://api.github.com/repos/' . GF_CIVICRM_PLUGIN_GITHUB_REPO . '/releases?per_page=5'; // GFCV-72 Temp. Allow prereleases for upgrader.
        } else {
            $plugin_update_uri = 'https://api.github.com/repos/' . GF_CIVICRM_PLUGIN_GITHUB_REPO . '/releases/latest';
        }

        $this->plugin_uri               = $plugin_data['PluginURI'];
        $this->plugin_update_uri        = $plugin_update_uri;
        $this->plugin                   = plugin_basename( $plugin_file );
        $this->name                     = $plugin_data['PluginName'];
		$this->slug                     = basename( dirname( $plugin_file ) );
		$this->version                  = $plugin_data['Version'];
        $this->author                   = $plugin_data['Author'];
        $this->author_uri               = $plugin_data['AuthorURI'];

        self::$_instance = $this;
    }

    /**
     * Initialize the updater by hooking into WordPress.
     */
    public function init() {
        add_filter( 'pre_set_site_transient_update_plugins', [$this, 'check_for_update'] );
        add_filter( 'plugins_api', [$this, 'plugins_api_filter'], 10, 3 );
        add_filter( 'upgrader_source_selection', [$this, 'fix_plugin_directory_name'], 10, 4 );

        // 1.10.3
        add_action( 'upgrader_process_complete', [$this, 'upgrade_version_1_10_3'], 10, 2 );
        
        add_action( 'admin_init', function() {
            // Optionally rollback webhook urls to the previous saved version
            if ( isset( $_GET['rollback_webhook_urls'] ) && isset( $_GET['page'] ) && $_GET['page'] === 'gf_settings' ) {
                $this->rollback_gravity_forms_webhook_urls();
                echo 'Webhook URLs have been reverted to their original values.';
            }
        });
    }

    /**
     * Check for plugin updates.
     *
     * @param object $transient The current update transient.
     * @return object Modified update transient with potential update data.
     */
    public function check_for_update( $transient ) {
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

        if ( !$update_info ) {
            return $transient;
        }

        $latest_version = $update_info->tag_name;

        // Compare versions and add the update info if a newer version is available
        if ( version_compare( $current_version, $latest_version, '<' ) ) {
            $plugin = [
                'slug'        => $this->slug,
                'plugin'      => $this->plugin,
                'name'        => $this->name,
                'new_version' => $latest_version,
                'url'         => $update_info->html_url,
                'package'     => $update_info->zipball_url
            ];

            $transient->response[$this->plugin] = (object) $plugin;
        }

        return $transient;
    }

    /**
     * Updates information on the "View version x.x details" page with custom data.
	 *
	 *
	 * @param mixed   $result
	 * @param string  $action
	 * @param object  $args
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
        $plugin_info->name = $this->name . ' version ' . $update_info->tag_name;
        $plugin_info->slug = $this->plugin;
        $plugin_info->version = ltrim($update_info->tag_name, 'v');
        $plugin_info->author = '<a href="' . $this->author_uri . '">' . $this->author . '</a>';
        $plugin_info->homepage = $this->plugin_uri;
        $plugin_info->download_link = $update_info->zipball_url;
        $plugin_info->sections = [
            'Release Notes' => $update_info->html_body,
        ];

		return $plugin_info;
	}

    /**
     * Convert the Markdown body to HTML using GitHub's Markdown API
     * 
     * @param string    $markdown_body
	 * @return string
     */
    private function convert_markdown_to_html( $markdown_body ) {
        // GitHub API URL for converting Markdown to HTML
        $markdown_url = 'https://api.github.com/markdown';

        // Convert the markdown to HTML
        $response = wp_remote_post( $markdown_url, [
            'headers' => [
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'WordPress Plugin Updater',
            ],
            'body' => json_encode([
                'text'   => $markdown_body,
                'mode'   => 'gfm',
                'context' => GF_CIVICRM_PLUGIN_GITHUB_REPO
            ]),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'Error converting Markdown to HTML via GitHub API : ' . $response->get_error_message() );
            return false;
        }

        $html_body = wp_remote_retrieve_body( $response );

        return $html_body;
    }

    /**
     * Get the latest release information from cached data or from remote repository.
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

            // If we retrieved an array (i.e. if prereleases are enabled), then grab the latest
            if ( is_array( $version_info ) ) {
                $version_info = reset($version_info);
            }

			// This is required to support auto-updates since WordPress 5.5.
			$version_info->plugin   = $this->name;
			$version_info->id       = $this->name;
			$version_info->version  = $version_info->new_version ?? $version_info->tag_name;
			$version_info->author   = sprintf( '<a href="%s">%s</a>', esc_url($this->author_uri), esc_html($this->author) );

            // Cache the body after converting from Markdown to HTML
            $version_info->html_body = $this->convert_markdown_to_html( $version_info->body );

			$this->set_version_info_cache( $version_info );
		}

		return $version_info;
    }

    /**
     * Get the latest release information from the GitHub repository.
     *
     * @return object|false Latest release information or false on failure.
     */
    private function get_update_info_from_remote() {
        // Set up the request headers, including a User-Agent as GitHub requires this.
        $args = [
            'headers' => [
                'User-Agent' => $this->name . ' Plugin Request', // GitHub API requires a User-Agent header.
                'Accept' => 'application/vnd.github+json',
            ],
        ];
        if ( defined( 'GITHUB_ACCESS_TOKEN_TESTING_ONLY' ) ) {
            $args['headers']['Authorization'] = 'token ' . GITHUB_ACCESS_TOKEN_TESTING_ONLY; // Use GitHub Access Token
        }
        $response = wp_remote_get( $this->plugin_update_uri, $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body );

        if ( empty( $data ) || isset( $data->message ) ) {
            return false;
        }

        // If we retrieved an array (i.e. if prereleases are enabled), then grab the latest
        if ( is_array( $data ) ) {
            // Run through the returned releases (including prereleases) and get the latest release by semantic tag
            foreach ( $data as $release ) {
                if ( isset( $release->prerelease ) && $release->prerelease && version_compare( $this->version, $release->tag_name, '<' ) ) {
                    return $release;
                }
            }
        }
        

        return $data;
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

        $cache = get_transient( $cache_key );

		return $cache;
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

        // Set the transient to expire in 12 hours (12 * 60 * 60 seconds)
        set_transient( $cache_key, $value, 12 * 60 * 60 );
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

    /**
     * Fix the directory name of the plugin during the update process.
     * 
     * Releases pulled from GitHub ZIP downloads use directory names that can include the GitHub username,
     * repository name, branch, and version. This results in incorrect folder structure when WordPress 
     * runs updates, because WordPress expects the ZIP file to contain exactly one directory with the 
     * same name as the directory where the plugin is currently installed.
     * 
     * We need to change the name of the folder downloaded from GitHub to the actual plugin folder name. 
     *
     * @param string $source        The source path of the update.
     * @param string $remote_source The remote source path of the update.
     * @param WP_Upgrader $upgrader The WP_Upgrader instance performing the update.
     * @param array $hook_extra     Additional arguments passed to the filter.
     * @return string Modified source path.
     */
    function fix_plugin_directory_name( $source, $remote_source, $upgrader, $hook_extra ) {
        global $wp_filesystem;

        //Basic sanity checks.
        if ( !isset( $source, $remote_source, $upgrader, $wp_filesystem ) ) {
            return $source;
        }

        // Check if we're updating this plugin
        if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->plugin ) {
            // Get the current directory name
            $currentDirectory = basename( GF_CIVICRM_PLUGIN_PATH );

            // Define the expected directory structure
            $correctedSource = trailingslashit( $remote_source ) . $currentDirectory . '/';
            
            // Check if the extracted directory matches the expected name
            if ( $source !== $correctedSource ) {
                if ( $wp_filesystem->move( $source, $correctedSource, true ) ) {
					// error_log( 'Successfully renamed the directory.' );
					return $correctedSource;
				} else {
					// error_log( 'Unable to rename the update to match the existing directory.' );
                    return new \WP_Error( 'rename_failed', __('Failed to rename plugin directory to match the existing directory.') );
				}
            }
        }

        // Return the original source if no changes are needed
        return $source;
    }

    /**
     * Runs the 1.10.3 Upgrade
     */
    function upgrade_version_1_10_3( $upgrader, $hook_extra ) {
        // Check if we're updating this plugin
        if ( $hook_extra['action'] != 'update' || $hook_extra['type'] != 'plugin' ) {
            return;
        }
        
        if ( !is_array( $hook_extra['plugins'] ) || !in_array( $this->plugin, $hook_extra['plugins'], true ) ) {
            return;
        }

        // Define the minimum target version for this upgrade action.
        $target_version = '1.10.3';

        // Get the current version (after the update).
        $current_version = $this->version;

        // Retrieve the stored version, or initialize it if not set.
        $previous_version = get_option('gfcv_version', false);

        // If no version is stored, or the previous version is less than the target version, run the upgrade script.
        if ( $previous_version === false || version_compare($previous_version, $target_version, '<') ) {
            // Add post-upgrade actions here.
            $this->execute_webhook_url_merge_tags_replacements();

            // Update the stored version to the current version.
            update_option( 'gfcv_version', $current_version );

            // Log the upgrade.
            error_log('Gravity Forms CiviCRM Integration upgrade 1.10.3 complete.');
        }
    }
    
    function rollback_gravity_forms_webhook_urls() {
        // Retrieve the backup data.
        $backup_data = get_option('gfcv_webhook_urls_backup', []);
    
        // Restore the original request URLs.
        foreach ($backup_data as $feed_id => $original_url) {
            $feed = GFAPI::get_feed($feed_id);
    
            if ($feed && isset($feed['meta']['requestURL'])) {
                $feed['meta']['requestURL'] = $original_url;
                GFAPI::update_feed($feed_id, $feed['meta']);
            }
        }
    }

    /**
     * Replaces CiviCRM site keys and API keys in Gravity Forms webhook request URLs with their
     * equivalent merge tags, for all webhooks feeds.
     */
    public function execute_webhook_url_merge_tags_replacements() {
        $forms = GFAPI::get_forms( null ); // Include inactive forms, but not trashed forms.
    
        // No forms found, do nothing
        if ( empty( $forms ) ) {
            return false;
        }
    
        // Prepare backup data for possible rollbacks
        $backup_data = [];
    
        foreach ( $forms as $form ) {
            $form_id = $form['id'];
            $feeds = GFAPI::get_feeds( form_ids: [ $form_id ] );
    
            if( $feeds instanceof \WP_Error ) {
                $feeds = [];
            }
    
            // Get just the gravityformswebhooks
            $webhook_feeds = array_filter( $feeds, function( $feed ) {
                return isset( $feed['addon_slug'] ) && $feed['addon_slug'] === 'gravityformswebhooks';
            });
    
            // No feeds found, do nothing
            if ( empty( $webhook_feeds ) ) {
                continue;
            }
    
            $errors = [];
            foreach ( $webhook_feeds as $feed ) {
                // Parse the URL to extract query parameters
                $parsed_url = parse_url( $feed['meta']['requestURL'] ?? '' );
                if ( ! isset( $parsed_url['query'] ) ) {
                    continue;
                }
    
                // Parse query parameters into an associative array
                parse_str( $parsed_url['query'], $query_params );

                // Exit early if this isn't a FormProcessor webhook
                if ( ! isset( $query_params['entity'] ) ||
                     $query_params['entity'] !== 'FormProcessor' ) {
                    continue;
                }
    
                // Return the `key` and `api_key` parameters if they exist
                $site_key_query_param = $query_params['key'] ?? null;
                $api_key_query_param = $query_params['api_key'] ?? null;
    
                // Replace them with the merge tags
                $query_params['key'] = $site_key_query_param ? '{gf_civicrm_site_key}' : null;
                $query_params['api_key'] = $api_key_query_param ? '{gf_civicrm_api_key}' : null;

                // Update the Site Key and API Key plugin settings
                if ( $form['is_active'] ) {
                    $this->update_sitekey_apikey_plugin_settings( $site_key_query_param, $api_key_query_param);
                }
    
                // Modify the webhook URL in the feed settings
                $rest_api_url = '{rest_api_url}civicrm/v3/rest';
                $old_url = rgar($feed['meta'], 'requestURL'); // Store this for rollback if needed
                $new_url = add_query_arg($query_params, $rest_api_url);
    
                // Update the request URL in the feed meta
                if ( $new_url !== $old_url ) {
                    $feed['meta']['requestURL'] = $new_url;
                    // Save the updated feed settings
                    $result = GFAPI::update_feed($feed['id'], $feed['meta']);

                    if (is_wp_error($result)) {
                        // Log the error
                        error_log("Error: Failed to update Gravity Forms Webhook URL for feed ID {$feed['id']} from {$old_url} to {$new_url}");
                        $errors[] = $result;
                    } else {
                        // Log the update
                        error_log("Updated Gravity Forms Webhook URL for feed ID {$feed['id']} from {$old_url} to {$new_url}");
                    }
    
                    // Store the old URL for possible rollbacks
                    $backup_data[$feed['id']] = $old_url;
                }
            }
        }
    
        // Store the backup in the wp_options table.
        if ( !empty($backup_data) ) {
            update_option('gfcv_webhook_urls_backup', $backup_data);
        }

        if (count($errors) > 0) {
            return false;
        }

        return true;
    }

    /**
     * Updates the CiviCRM Site Key and API Key plugin settings.
     */
    function update_sitekey_apikey_plugin_settings( $site_key, $api_key ) {
        // Get the current settings
        $current_settings = FieldsAddOn::get_instance()->get_plugin_settings();

        // Update the Site Key and API Key settings, only IF they aren't already populated
        if ( !isset( $current_settings['gf_civicrm_site_key'] ) || empty( $current_settings['gf_civicrm_site_key'] ) ) {
            $current_settings['gf_civicrm_site_key'] = $site_key != '{gf_civicrm_site_key}' ? $site_key : $current_settings['gf_civicrm_site_key'];
        }

        if ( !isset( $current_settings['gf_civicrm_api_key'] ) || empty( $current_settings['gf_civicrm_api_key'] ) ) {
            $current_settings['gf_civicrm_api_key'] = $api_key != '{gf_civicrm_api_key}' ? $api_key : $current_settings['gf_civicrm_api_key'];
        }
        
        FieldsAddOn::get_instance()->update_plugin_settings($current_settings);
    }
}