<?php
/**
 * Plugin Name: GF CiviCRM test helpers
 * Description: Test-only helpers for the gf-civicrm Playwright suite. Copied into wp-content/mu-plugins by tests/playwright/fixtures/setup-environment.sh. Never shipped with the plugin.
 */

/**
 * Loopback. In CI the site URL is http://localhost:8080, but port 8080 only exists on the
 * runner - inside the WordPress container Apache listens on port 80. Webhook requests to
 * {rest_api_url} (and Gravity Forms' background-processing loopback) would otherwise fail
 * with "connection refused". Reissue those requests against port 80.
 */
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$from = 'http://localhost:8080/';
	if ( false === $pre && is_string( $url ) && str_starts_with( $url, $from ) ) {
		return wp_remote_request( 'http://localhost/' . substr( $url, strlen( $from ) ), $args );
	}
	return $pre;
}, 10, 3 );

/**
 * The remote-connection suite points a CMRF profile at http://localhost/ (the site itself, from
 * inside the container). wp_safe_remote_request() rejects internal hosts unless they match the
 * site's own host, which is true in CI (localhost) but not under ddev, so allow it explicitly.
 */
add_filter( 'http_request_host_is_external', function ( $external, $host ) {
	return 'localhost' === $host ? true : $external;
}, 10, 2 );

/**
 * Synchronous feeds, so the webhook has run and the entry meta is written before the
 * confirmation page loads. Asynchronous retries are verified manually (test plan F-10).
 */
add_filter( 'gform_is_feed_asynchronous', '__return_false' );

/**
 * Mail capture. Each message is stored in the gfcvtest_mail option instead of being sent;
 * specs read and clear it through wp-cli (fixtures/mail.ts).
 */
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$log   = get_option( 'gfcvtest_mail', [] );
	$log[] = [
		'to'      => $atts['to'],
		'subject' => $atts['subject'],
		'message' => $atts['message'],
	];
	update_option( 'gfcvtest_mail', $log, false );
	return true;
}, 10, 2 );

/**
 * Capture endpoint. Records the query string, headers and body of the last request, so
 * specs can assert exactly what a webhook sent.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'gfcvtest/v1', '/capture', [
		'methods'             => [ 'GET', 'POST' ],
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $request ) {
			update_option( 'gfcvtest_capture', [
				'query'   => $request->get_query_params(),
				'headers' => $request->get_headers(),
				'body'    => $request->get_body(),
			], false );
			return [ 'ok' => true ];
		},
	] );
} );

/**
 * Prints a nonce for the logged-in user, so a capability check can be tested separately
 * from the nonce check in front of it.
 */
add_shortcode( 'gfcvtest_nonce', function ( $atts ) {
	$atts = shortcode_atts( [ 'action' => '' ], $atts );
	return '<span id="gfcvtest-nonce">' . esc_html( wp_create_nonce( $atts['action'] ) ) . '</span>';
} );
