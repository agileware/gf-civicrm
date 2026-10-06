<?php
/**
 * Seeds the Gravity Forms forms, Webhooks feeds and host pages the Playwright suite drives,
 * and the plugin settings they depend on.
 *
 * Run with `wp eval-file seed-forms.php <site_key> <api_key>`, after seed-data.php (several
 * forms reference CiviCRM records by id). Idempotent: forms are matched by title and
 * replaced, feeds are recreated, and pages are matched by slug.
 *
 * The form ids are recorded in the gfcvtest_forms option, keyed by the short names used
 * in the specs, so a spec never has to look a form up by title.
 */

[ $site_key, $api_key ] = array_map( 'trim', $args + [ '', '' ] );

if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GFCiviCRM\FieldsAddOn' ) ) {
	WP_CLI::error( 'Gravity Forms and gf-civicrm must be active before seeding forms.' );
}

civi_wp()->initialize();

// ---------------------------------------------------------------- CiviCRM ids

$group_id       = (int) \Civi\Api4\Group::get( FALSE )->addWhere( 'name', '=', 'gfcvtest_group' )->execute()->first()['id'];
$empty_group_id = (int) \Civi\Api4\Group::get( FALSE )->addWhere( 'name', '=', 'gfcvtest_empty_group' )->execute()->first()['id'];
$search_id      = (int) \Civi\Api4\SavedSearch::get( FALSE )->addWhere( 'name', '=', 'gfcvtest_saved_search' )->execute()->first()['id'];
$processor_id   = (int) \Civi\Api4\PaymentProcessor::get( FALSE )
	->addWhere( 'name', '=', 'gfcvtest_dummy' )
	->addWhere( 'is_test', '=', FALSE )
	->execute()->first()['id'];

if ( ! $group_id || ! $empty_group_id || ! $search_id || ! $processor_id ) {
	WP_CLI::error( 'CiviCRM seed records are missing - run seed-data.php first.' );
}

// ---------------------------------------------------------------- plugin settings

$addon    = GFCiviCRM\FieldsAddOn::get_instance();
$settings = $addon->get_plugin_settings() ?: [];
$addon->update_plugin_settings( array_merge( $settings, [
	'gf_civicrm_site_key'                => $site_key,
	'gf_civicrm_api_key'                 => $api_key,
	'civicrm_multi_json'                 => '1',
	'enable_prereleases'                 => '0',
	'enable_emails'                      => '1',
	'gf_civicrm_alerts_email'            => 'gfcv-alerts@example.test',
	'civicrm_address_country_format'     => 'name',
	'gf_civicrm_import_export_directory' => 'wp-content/uploads/gfcvtest-exports',
	'civicrm_rest_connection'            => '',
] ) );

// ---------------------------------------------------------------- helpers

/** Webhooks feed body/header mapping entry: a custom key mapped to a field id. */
function gfcvtest_map( string $key, string $field_id ): array {
	return [ 'key' => 'gf_custom', 'custom_key' => $key, 'value' => $field_id, 'custom_value' => '' ];
}

/** Webhooks feed body/header mapping entry: a custom key mapped to a custom value. */
function gfcvtest_custom( string $key, string $value ): array {
	return [ 'key' => 'gf_custom', 'custom_key' => $key, 'value' => 'gf_custom', 'custom_value' => $value ];
}

function gfcvtest_feed( string $name, string $url, array $field_values, array $headers = [] ): array {
	return [
		'feedName'                         => $name,
		'requestURL'                       => $url,
		'requestMethod'                    => 'POST',
		'requestFormat'                    => 'json',
		'requestHeaders'                   => $headers,
		'requestBodyType'                  => 'select_fields',
		'fieldValues'                      => $field_values,
		'feed_condition_conditional_logic' => '0',
	];
}

/**
 * Create or replace a form by title, recreate its Webhooks feeds, and host it on a page.
 *
 * @return int The form id.
 */
function gfcvtest_form( string $title, array $fields, array $extra = [], array $feeds = [], string $slug = '' ): int {
	// The form editor stores inputType on every field (e.g. "select" for a Drop Down), and the
	// plugin reads it, so seeded fields must carry it too to behave like real forms.
	foreach ( $fields as &$field ) {
		$field['inputType'] = $field['inputType'] ?? $field['type'];
	}
	unset( $field );

	$form = array_merge( [
		'title'                => $title,
		'description'          => '',
		'labelPlacement'       => 'top_label',
		'button'               => [ 'type' => 'text', 'text' => 'Submit' ],
		'fields'               => $fields,
		'is_active'            => true,
		'markupVersion'        => 2,
		'confirmations'        => [
			'gfcvtest' => [
				'id'        => 'gfcvtest',
				'name'      => 'Default Confirmation',
				'isDefault' => true,
				'type'      => 'message',
				'message'   => 'GFCVTEST SUBMITTED',
			],
		],
		'notifications'        => [],
	], $extra );

	$existing = null;
	foreach ( GFAPI::get_forms( null ) as $candidate ) {
		if ( $candidate['title'] === $title ) {
			$existing = $candidate;
			break;
		}
	}

	if ( $existing ) {
		$form['id'] = $existing['id'];
		$result     = GFAPI::update_form( $form );
		$form_id    = (int) $existing['id'];
		foreach ( GFAPI::get_feeds( null, $form_id, null, null ) as $feed ) {
			if ( is_array( $feed ) ) {
				GFAPI::delete_feed( $feed['id'] );
			}
		}
	} else {
		$result  = GFAPI::add_form( $form );
		$form_id = (int) $result;
	}

	if ( is_wp_error( $result ) ) {
		WP_CLI::error( "Could not save form {$title}: " . $result->get_error_message() );
	}

	foreach ( $feeds as $feed ) {
		GFAPI::add_feed( $form_id, $feed, 'gravityformswebhooks' );
	}

	if ( $slug ) {
		gfcvtest_page( $slug, $title, sprintf( '[gravityform id="%d" title="false" ajax="false"]', $form_id ) );
	}

	return $form_id;
}

function gfcvtest_page( string $slug, string $title, string $content ): void {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	$post     = [
		'post_type'    => 'page',
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_status'  => 'publish',
		'post_content' => $content,
	];
	if ( $existing ) {
		$post['ID'] = $existing->ID;
		wp_update_post( $post );
	} else {
		wp_insert_post( $post );
	}
}

$colours = 'civicrm__gfcvtest_colours';
$rest    = '{rest_api_url}civicrm/v3/rest?entity=FormProcessor&action=%s&key={gf_civicrm_site_key}&api_key={gf_civicrm_api_key}&json=1';
$capture = '{rest_api_url}gfcvtest/v1/capture';
$forms   = [];

// ---------------------------------------------------------------- Suite A: CiviCRM Source

$forms['sources'] = gfcvtest_form( 'GFCVTEST Sources', [
	[ 'type' => 'select', 'id' => 1, 'label' => 'Colour Select', 'choices' => [], 'civicrmOptionGroup' => $colours ],
	[ 'type' => 'radio', 'id' => 2, 'label' => 'Colour Radio', 'choices' => [], 'civicrmOptionGroup' => $colours ],
	[ 'type' => 'checkbox', 'id' => 3, 'label' => 'Colour Checkboxes', 'choices' => [], 'inputs' => [], 'civicrmOptionGroup' => $colours ],
	[ 'type' => 'multiselect', 'id' => 4, 'label' => 'Colour Multi Select', 'choices' => [], 'civicrmOptionGroup' => $colours, 'storageType' => 'json' ],
	[ 'type' => 'select', 'id' => 5, 'label' => 'Colour Required', 'choices' => [], 'civicrmOptionGroup' => $colours, 'isRequired' => true ],
	[ 'type' => 'select', 'id' => 6, 'label' => 'Colour From Form Processor', 'choices' => [], 'civicrmOptionGroup' => 'civicrm_fp__gfcvtest_contact__colour' ],
	[ 'type' => 'radio', 'id' => 7, 'label' => 'Colour Radio Default', 'choices' => [], 'civicrmOptionGroup' => $colours, 'defaultValue' => 'Blue' ],
	[ 'type' => 'checkbox', 'id' => 8, 'label' => 'Colour Checkboxes Default', 'choices' => [], 'inputs' => [], 'civicrmOptionGroup' => $colours, 'defaultValue' => 'gfcv_red,gfcv_blue' ],
	[ 'type' => 'multiselect', 'id' => 9, 'label' => 'Colour Multi Select Default', 'choices' => [], 'civicrmOptionGroup' => $colours, 'defaultValue' => 'gfcv_red,gfcv_blue', 'storageType' => 'json' ],
], [], [], 'gfcv-test-sources' );

// ---------------------------------------------------------------- Suite B: defaults

$forms['defaults'] = gfcvtest_form( 'GFCVTEST Defaults', [
	[ 'type' => 'text', 'id' => 1, 'label' => 'First Name Default', 'defaultValue' => '{civicrm_fp.gfcvtest_contact.first_name}' ],
	[ 'type' => 'text', 'id' => 2, 'label' => 'Last Name Via Default FP', 'defaultValue' => '{civicrm_fp.default_fp.last_name}' ],
	[ 'type' => 'html', 'id' => 3, 'label' => 'Info', 'content' => 'HTMLFIRST=[{civicrm_fp.default_fp.first_name}] RESTURL=[{gf_civicrm_rest_url}]' ],
	[ 'type' => 'text', 'id' => 4, 'label' => 'Restricted Default', 'defaultValue' => '{civicrm_fp.gfcvtest_restricted.code}' ],
	[ 'type' => 'text', 'id' => 5, 'label' => 'Missing Processor Default', 'defaultValue' => '{civicrm_fp.gfcvtest_nonexistent.anything}' ],
], [ 'gf-civicrm' => [ 'default_fp' => 'gfcvtest_contact' ] ], [], 'gfcv-test-defaults' );

// ---------------------------------------------------------------- Suite C: Group Contact Select

$forms['contactSelect'] = gfcvtest_form( 'GFCVTEST Contact Select', [
	[ 'type' => 'group_contact_select', 'id' => 1, 'label' => 'Group Contact', 'choices' => [], 'civicrm_group' => (string) $group_id ],
	[ 'type' => 'group_contact_select', 'id' => 2, 'label' => 'Saved Search Contact', 'choices' => [], 'civicrm_group' => 'ss:' . $search_id ],
	[ 'type' => 'group_contact_select', 'id' => 3, 'label' => 'Empty Group Contact', 'choices' => [], 'civicrm_group' => (string) $empty_group_id ],
], [], [], 'gfcv-test-contact-select' );

$forms['contactSelectRequired'] = gfcvtest_form( 'GFCVTEST Contact Select Required', [
	[ 'type' => 'group_contact_select', 'id' => 1, 'label' => 'Group Contact Required', 'choices' => [], 'civicrm_group' => (string) $group_id, 'isRequired' => true ],
], [], [], 'gfcv-test-contact-select-required' );

// ---------------------------------------------------------------- Suite D: Payment Token

$forms['paymentToken'] = gfcvtest_form( 'GFCVTEST Payment Token', [
	[ 'type' => 'civicrm_payment_token', 'id' => 1, 'label' => 'Saved Card', 'choices' => [], 'civicrm_payment_processor' => (string) $processor_id ],
	[ 'type' => 'hidden', 'id' => 2, 'label' => 'cid', 'allowsPrepopulate' => true, 'inputName' => 'cid' ],
	[ 'type' => 'hidden', 'id' => 3, 'label' => 'cs', 'allowsPrepopulate' => true, 'inputName' => 'cs' ],
], [], [], 'gfcv-test-payment-token' );

$forms['paymentTokenRequired'] = gfcvtest_form( 'GFCVTEST Payment Token Required', [
	[ 'type' => 'civicrm_payment_token', 'id' => 1, 'label' => 'Saved Card Required', 'choices' => [], 'civicrm_payment_processor' => (string) $processor_id, 'isRequired' => true ],
], [], [], 'gfcv-test-payment-token-required' );

// ---------------------------------------------------------------- Suite E: Address

$forms['address'] = gfcvtest_form( 'GFCVTEST Address', [
	[
		'type'           => 'address',
		'id'             => 1,
		'label'          => 'Address',
		'addressType'    => 'international',
		'defaultCountry' => 'AU',
		'isRequired'     => true,
		'inputs'         => [
			[ 'id' => '1.1', 'label' => 'Street Address' ],
			[ 'id' => '1.2', 'label' => 'Address Line 2' ],
			[ 'id' => '1.3', 'label' => 'City' ],
			[ 'id' => '1.4', 'label' => 'State / Province / Region' ],
			[ 'id' => '1.5', 'label' => 'ZIP / Postal Code' ],
			[ 'id' => '1.6', 'label' => 'Country' ],
		],
	],
], [], [
	gfcvtest_feed( 'GFCVTEST Address Capture', $capture, [ gfcvtest_map( 'country', '1.6' ), gfcvtest_map( 'state', '1.4' ) ] ),
], 'gfcv-test-address' );

// ---------------------------------------------------------------- Suite F: webhooks

$webhook_fields = [
	[ 'type' => 'text', 'id' => 1, 'label' => 'First Name' ],
	[ 'type' => 'text', 'id' => 2, 'label' => 'Last Name' ],
	[ 'type' => 'email', 'id' => 3, 'label' => 'Email', 'isRequired' => true ],
	[ 'type' => 'select', 'id' => 4, 'label' => 'Colour', 'choices' => [], 'civicrmOptionGroup' => $colours ],
	[ 'type' => 'checkbox', 'id' => 5, 'label' => 'Colours', 'choices' => [], 'inputs' => [], 'civicrmOptionGroup' => $colours ],
	[ 'type' => 'multiselect', 'id' => 6, 'label' => 'Colours Multi', 'choices' => [], 'civicrmOptionGroup' => $colours, 'storageType' => 'json' ],
	[ 'type' => 'product', 'inputType' => 'price', 'id' => 7, 'label' => 'Amount' ],
	[ 'type' => 'fileupload', 'id' => 8, 'label' => 'Attachment', 'allowedExtensions' => 'txt' ],
];
$forms['webhook'] = gfcvtest_form( 'GFCVTEST Webhook', $webhook_fields, [], [
	gfcvtest_feed( 'GFCVTEST Contact Feed', sprintf( $rest, 'gfcvtest_contact' ), [
		gfcvtest_map( 'first_name', '1' ),
		gfcvtest_map( 'last_name', '2' ),
		gfcvtest_map( 'email', '3' ),
		gfcvtest_map( 'colour', '4' ),
		gfcvtest_map( 'colours', '5' ),
		gfcvtest_map( 'colours_multi', '6' ),
		gfcvtest_map( 'amount', '7' ),
		gfcvtest_map( 'attachment', '8' ),
	] ),
], 'gfcv-test-webhook' );

$forms['webhookFail'] = gfcvtest_form( 'GFCVTEST Webhook Fail', [
	[ 'type' => 'email', 'id' => 1, 'label' => 'Email', 'isRequired' => true ],
], [], [
	gfcvtest_feed( 'GFCVTEST Failing Feed', sprintf( $rest, 'gfcvtest_nonexistent' ), [ gfcvtest_map( 'email', '1' ) ] ),
], 'gfcv-test-webhook-fail' );

// ---------------------------------------------------------------- Suite G: merge tag scope

$forms['mergeTags'] = gfcvtest_form( 'GFCVTEST Merge Tags', [
	[ 'type' => 'hidden', 'id' => 1, 'label' => 'Hidden Key', 'defaultValue' => '{gf_civicrm_api_key}' ],
	[ 'type' => 'text', 'id' => 2, 'label' => 'Echo' ],
], [
	'confirmations' => [
		'gfcvtest' => [
			'id'        => 'gfcvtest',
			'name'      => 'Default Confirmation',
			'isDefault' => true,
			'type'      => 'message',
			'message'   => 'CONFIRM API=[{gf_civicrm_api_key}] SITE=[{gf_civicrm_site_key}] ECHO=[{Echo:2}]',
		],
	],
	'notifications' => [
		'gfcvtest' => [
			'id'       => 'gfcvtest',
			'name'     => 'GFCVTEST Notification',
			'isActive' => true,
			'event'    => 'form_submission',
			'toType'   => 'email',
			'to'       => 'gfcv-notify@example.test',
			'subject'  => 'GFCVTEST notification',
			'message'  => 'NOTIFY API=[{gf_civicrm_api_key}] SITE=[{gf_civicrm_site_key}]',
		],
	],
], [
	gfcvtest_feed(
		'GFCVTEST Capture Feed',
		$capture . '?key={gf_civicrm_site_key}&api_key={gf_civicrm_api_key}',
		[ gfcvtest_custom( 'site', '{gf_civicrm_site_key}' ), gfcvtest_map( 'echo', '2' ) ],
		// Authentication headers go to the capture endpoint, not CiviCRM: CiviCRM's AuthX reads
		// them and would reject the dummy bearer token.
		[
			gfcvtest_custom( 'X-Civi-Key', '{gf_civicrm_api_key}' ),
			gfcvtest_custom( 'Authorization', 'Bearer gfcvtest-not-a-real-token' ),
		]
	),
], 'gfcv-test-merge-tags' );

// ---------------------------------------------------------------- a plain form (H-11 sets its legacy checksum setting)

$forms['counter'] = gfcvtest_form( 'GFCVTEST Counter', [
	[ 'type' => 'text', 'id' => 1, 'label' => 'Long Text', 'maxLength' => 500 ],
], [], [], 'gfcv-test-counter' );

// ---------------------------------------------------------------- host pages without forms

// Prints a nonce for the logged-in user (see mu-plugins/gfcv-test-helpers.php).
gfcvtest_page( 'gfcv-test-nonce', 'GFCV Test Nonce', '[gfcvtest_nonce action="gf_civicrm_ajax_nonce"]' );

update_option( 'gfcvtest_forms', $forms, false );

WP_CLI::success( 'Seeded forms: ' . wp_json_encode( $forms ) );
