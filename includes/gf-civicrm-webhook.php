<?php

namespace GFCiviCRM;

/**
 * Replace request data output with json-decoded structures where applicable.
 *
 * @param $request_data
 * @param $feed
 * @param $entry
 * @param $form
 *
 * @return array
 */
function webhooks_request_data( $request_data, $feed, $entry, $form ) {
	// Form hooks don't seem to be called during webform request, so do it ourselves
	$form = do_civicrm_replacement( $form, 'webhook_request_data' );

	if ( $feed['meta']['requestFormat'] === 'json' ) {
    	$rewrite_data = [];

		$multi_json = (bool) FieldsAddOn::get_instance()->get_plugin_setting( 'civicrm_multi_json' );

		$feed_keys = [];

		// Find the field ids to process
		foreach( $feed[ 'meta' ][ 'fieldValues' ] as $fv ) {
			$feed_keys[ $fv[ 'value' ] ] = $fv[ 'custom_key' ];
		}

		/** @var \GF_Field $field */
		foreach ( $form['fields'] as $field ) {
			// Skip if not part of entry meta
			if( !$feed_keys[ $field->id ] ) {
				continue;
			}

			// Send multi-value fields encode in json instead of comma separated
			if ( $feed['meta']['requestFormat'] === 'json' ) {
				if ( property_exists( $field, 'storageType' ) && $field->storageType == 'json' ) {
					$rewrite_data[ $field['id'] ] = json_decode( $entry[ $field['id'] ] );
				} elseif (
					! empty( $multi_json ) &&  // JSON encoding selected in settings
					( is_a( $field, 'GF_Field_Checkbox' ) || is_a( $field, 'GF_Field_MultiSelect' ) ) // Multi-value field
				) {
					$rewrite_data[ $field->id ] = fix_multi_values( $field, $entry );
				}
			}

			/*
			* Custom Price, Product fields send the value in $ 50.00 format which is problematic
			* @TODO If the $feed['meta']['fieldValues'][x] field has a value=gf_custom then custom_value will contain something like {membership_type:83:price} - this requires new logic extract the field ID. Will not contain the usual field ID.
			*/
			if ( is_a( $field, 'GF_Field_Price' ) && $field->inputType == 'price' && isset( $entry[ $field->id ] ) ) {
				$rewrite_data[ $field->id ] = convertInternationalCurrencyToFloat( $entry[ $field->id ] );
			}

			// URL encode file url parts.  The is mostly because PHP does not count URLs with UTF-8 in them as valid.
			if( is_a( $field, 'GF_Field_FileUpload' ) ) {
				// Assume the scheme + domain is already encoded, extract path part
				if(preg_match('{ (^ .+? ://+ .+? / ) ( .+ ) }x', $entry[ $field->id ], $matches)) {
					[, $base, $path] = $matches;

					// Each path, query, and fragment part, should be passed through urlencode
					$path = preg_replace_callback( '{ ( [^/?#&]+ ) }x', fn($part) => urlencode( $part[1] ), $path );

					// Recombine for the rewritten data
					$rewrite_data[ $field->id ] = $base . $path;
				}
			}
		}
		foreach ( $feed['meta']['fieldValues'] as $field_value ) {
			if ( ( ! empty( $field_value['custom_key'] ) ) && ( $value = $rewrite_data[ $field_value['value'] ] ?? NULL ) ) {
				$request_data[ $field_value['custom_key'] ] = $value;
			}
		}
	}

	return $request_data;
}

add_filter( 'gform_webhooks_request_data', 'GFCiviCRM\webhooks_request_data', 10, 4 );

/*
* Extend the maximum attempts for webhook calls, so Gravity Forms does not give up if unable to connect on first go
*/

add_filter( 'gform_max_async_feed_attempts', 'GFCiviCRM\custom_max_async_feed_attempts', 10, 5 );

function custom_max_async_feed_attempts( $max_attempts, $form, $entry, $addon_slug, $feed ) {
    if ( $addon_slug == 'gravityformswebhooks' ) {
        $max_attempts = 3;
    }
    return $max_attempts;
}

/**
 * Handles sending webhook alerts for failures to the alerts email address provided in the GF CiviCRM Settings.
 */
function webhook_alerts( $response, $feed, $entry, $form ) {
	if ( !class_exists( 'GFCiviCRM\FieldsAddOn' ) ) {
		return;
	}

	// Add the webhook response to the entry meta. Supports multiple feeds.
	$current_response = gform_get_meta( $entry['id'], 'webhook_feed_response' );
	if ( empty( $current_response ) ) {
		$current_response = [];
	}

	$error_code = null;
	$error_message 	= '';

	// Get the error message, and log it in the Gravity Forms logs (if enabled)
	if ( is_wp_error( $response ) ) {
		// If its a WP_Error
		$error_code = $response->get_error_code();
    	$error_message = $response->get_error_message();

		// Build the webhook response entry content
    	$webhook_feed_response = [ 
			'date' => current_datetime(),
			'body' => $error_message, 
			'response' => $error_code
		];

		GFCommon::log_debug( __METHOD__ . '(): WP_Error detected.' );
	} else {
		$response_data = $response['body'] ? json_decode($response['body'], true) : '';

		// Build the webhook response entry content
    	$webhook_feed_response = [ 
			'date' => $response['headers']['data']['date'],
			'body' => $response_data, 
			'response' => $response['response']
		];

		if ( is_wp_error( $response ) ) {
			// If its a WP_Error in the webhook feed response
			$error_code = $response->get_error_code();
			$error_message = $response->get_error_message();
	
			GFCommon::log_debug( __METHOD__ . '(): WP_Error detected.' );
		} 
		
		if ( isset( $response['response']['code'] ) && $response['response']['code'] >= 300 && strpos( $response['body'], 'is_error' ) == false ) {
			// If we get an error response
			$response_data = json_decode( $response['body'], true );
			
			$error_code = $response['response']['code'];
			$error_message = $response['response']['message'] . "\n" . ( $response_data['message'] ?? '' );
			
			GFCommon::log_debug( __METHOD__ . '(): Error detected.' );
		}
		
		if ( strpos( $response['body'], 'is_error' ) != false ) {
			// If is_error appears in the response body 
			// This may happen if the webhook response appears as a success, but the response value from the REST url is actually an error.

			// Extract the required values
			$response_data = json_decode( $response['body'], true );
			$is_error = $response_data['is_error'] ?? false;

			// Validate is_error before doing anything
			if ( $is_error ) {
				$error_code = $response_data['error_code'] ?? '';
				$error_message = $response_data['error_message'] ?? '';
			}

			GFCommon::log_debug( __METHOD__ . '(): Error detected.' );
		}
	}

	$current_response[$feed['id']] = $webhook_feed_response;
	gform_update_meta( $entry['id'], 'webhook_feed_response', $current_response );

	// Send an alert email if we have an error code
	if ( $error_code !== null ) {
		GFCommon::log_debug( __METHOD__ . '(): Error Message: ' . $error_message );

		// Do not continue if enable_emails is not true, or if no alerts email has been provided
		$plugin_settings = FieldsAddOn::get_instance()->get_plugin_settings();
    	if ( !isset($plugin_settings['enable_emails']) || !$plugin_settings['enable_emails'] || 
			!isset($plugin_settings['gf_civicrm_alerts_email']) || empty($plugin_settings['gf_civicrm_alerts_email']) ) {
			return;
		}

		// Build the alert email
		$to     		= $plugin_settings['gf_civicrm_alerts_email'];
		$subject 		= sprintf('Webhook failed on %s', get_site_url());
		$request_url 	= $feed['meta']['requestURL'];
		$entry_id 		= $entry['id'];

    	$request_url = apply_filters('gform_replace_merge_tags', $request_url, $form, $entry, false, false, false, 'html');

		$body    = sprintf(
			'Webhook feed on %s failed.' . "\n\n%s%s\n" . 'Feed: "%s" (ID: %s) from form "%s" (ID: %s)' . "\n" . 'Request URL: %s' . "\n" . 'Failed Entry ID: %s',
			get_site_url(),
			$error_code ? "Error Code: " . $error_code . "\n": '', 
			$error_message ? "Error: " . $error_message . "\n": '', 
			$feed['meta']['feedName'], 
			$feed['id'], 
			$form['title'], 
			$form['id'], 
			$request_url,
			$entry_id
    	);

		// Send an email to the nominated alerts email address
		wp_mail( $to, $subject, $body );
	}
}

add_action( 'gform_webhooks_post_request', 'GFCiviCRM\webhook_alerts', 10, 4);

/**
 * Save the webhook request to the Entry.
 * 
 * Request URL is processed before request args. We'll use both filters to build the request
 * data. Supports multiple feeds.
 */
add_filter( 'gform_webhooks_request_url', function ( $request_url, $feed, $entry, $form ) {
	// Add the webhook request to the entry meta
	$current_request = gform_get_meta( $entry['id'], 'webhook_feed_request' );

	if ( $current_request && is_array($current_request) ) {
		$current_request[$feed['id']] = [
			'request_url' => $request_url,
		];
	} else {
		$current_request = [
			$feed['id'] => [
				'request_url' => $request_url,
			],
		];
	}

	gform_update_meta( $entry['id'], 'webhook_feed_request', $current_request );
	return $request_url;
}, 10, 4 );

add_filter( 'gform_webhooks_request_args', function ( $request_args, $feed, $entry, $form ) {
	// Ensure that other WordPress plugins have not lowered the curl timeout which impacts Gravity Forms webhook requests.
	// Set timeout to 10 seconds
	$request_args['timeout'] = 10000;

	// Add the webhook request to the entry meta
	$current_request = gform_get_meta( $entry['id'], 'webhook_feed_request' );
    if (! is_array( $current_request ) ) {
		$current_request = [
			$feed['id'] => [
				'request_url' => $current_request
			]
		];
    }

    $current_request[$feed['id']] = [
      'request_url' => $current_request[$feed['id']]['request_url'] ?? '',
      'request_args' => $request_args,
    ];

	gform_update_meta( $entry['id'], 'webhook_feed_request', $current_request );
	return $request_args;
}, 10, 4 );

/**
 * Register custom Entry meta fields.
 */
add_filter( 'gform_entry_meta', function ( $entry_meta, $form_id ) {
	$entry_meta['webhook_feed_request'] = [
		'label'       => esc_html__( 'Webhook Request', 'gf-civicrm' ),
		'is_numeric'  => false,
		'update_entry_meta_callback' => null, // Optional callback for updating.
		'filter'      => true, // Enable filtering in the Entries UI
	];
	$entry_meta['webhook_feed_response'] = [
		'label'       => esc_html__( 'Webhook Response', 'gf-civicrm' ),
		'is_numeric'  => false,
		'update_entry_meta_callback' => null,
		'filter'      => true,
	];

	return $entry_meta;
}, 10, 2 );

/**
 * Display the webhook feed result as a metabox when viewing an entry.
 */
add_filter( 'gform_entry_detail_meta_boxes', function ( $meta_boxes, $entry, $form ) {
	// Add a new meta box for the webhook request.
	$meta_boxes['webhook_feed_request'] = [
		'title'    => esc_html__( 'Webhook Request', 'gf-civicrm' ),
		'callback' => function( $args ) {
			display_webhook_meta_box( $args, "webhook_feed_request" );
		},
		'context'  => 'normal', // Can be 'normal', 'side', or 'advanced'.
		'priority' => 'low', // Ensure the meta box appears at the bottom of the section.
	];
	// Add a new meta box for the webhook response.
	$meta_boxes['webhook_feed_response'] = [
		'title'    => esc_html__( 'Webhook Response', 'gf-civicrm' ),
		'callback' => function($args) {
			display_webhook_meta_box( $args, "webhook_feed_response" );
		},
		'context'  => 'normal',
		'priority' => 'low',
	];

	return $meta_boxes;
}, 10, 3 );

function display_webhook_meta_box( $args, $meta_key ) {
	// Don't display if the current user is not an admin.
	if (!in_array( 'administrator', (array) wp_get_current_user()->roles )) {
		echo '<p>' . esc_html__( 'You need the administrator role to view this data.', 'gf-civicrm' ) . '</p>';
		return;
	}

	$entry = $args['entry']; // Current entry object.

	// Retrieve the webhook meta data.
	$meta = rgar( $entry, $meta_key );

	if ( ! empty( $meta ) && is_array( $meta ) ) {
		// Display the response code and message.
		echo '<pre style="text-wrap: auto;">';
		print_r( $meta );
		echo '</pre>';
	} else {
		echo '<p>' . esc_html__( 'No data available.', 'gf-civicrm' ) . '</p>';
	}
}
