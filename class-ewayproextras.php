<?php

namespace GFCiviCRM;

use GFAddOn, GFForms;
use Civi\Api4\UFMatch;
use Civi\Api4\PaymentToken;
use GFCiviCRM\eWAYProExtras\PaymentAPI;
use webaware\gfewaypro\AddOn as GFeWAYProAddon;
use webaware\gfewaypro\Credentials;
use WP_User;

GFForms::include_addon_framework();

class eWAYProExtras extends GFeWAYProAddon {

	/**
	 * @var object $_instance If available, contains an instance of this class.
	 */
	private static $_instance = NULL;

	/**
	 * Returns an instance of GFCiviCRM\eWAYProExtras, and stores it in the
	 * $_instance property.
	 *
	 * @return \GFCiviCRM\eWAYProExtras $_instance
	 */
	public static function get_instance() {
		if ( self::$_instance == NULL ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Overrides Eway Payments Pro constructor with details for extended Add-On.
	 */
	public function __construct() {
		parent::__construct();

		$this->_path        = 'gf-civicrm/gf-civicrm.php';
		$this->_full_path   = __FILE__;
		$this->_title       .= ' with CiviCRM';
		$this->_short_title .= ' with CiviCRM';
	}

	protected function getPaymentRequest( $formData, $feed, $form, $entry = false ) {
		$requestor = parent::getPaymentRequest( $formData, $feed, $form, $entry );

		if ((($feed['meta']['rememberCustomer'] ?? 'off') !== 'off') &&
		    (($feed['meta']['feedMethod'] ?? null) === 'shared')) {
			$requestor->saveCustomer = true;
		}

		return $requestor;
	}

	/**
	 * Overrides "requestor" PaymentAPI object with our own extended version
	 *
	 * @param $feed
	 *
	 * @return PaymentAPI
	 */
	public function getPaymentRequestor( $feed ) {
		$creds = new Credentials( $this, $feed );

		return new PaymentAPI($creds->apiKey, $creds->password, $creds->customerID, $creds->useTest);
	}

	/**
	 * Defines additional meta fields for Card info
	 *
	 * @param $entry_meta
	 * @param $form_id
	 *
	 * @return array
	 */
	public function get_entry_meta( $entry_meta, $form_id ) {
		$entry_meta = parent::get_entry_meta( $entry_meta, $form_id );

		$entry_meta['eway_card'] = [
			'label'             => 'Masked Card Number',
			'is_numeric'        => FALSE,
			'is_default_column' => FALSE,
			'filter'            => [ 'operators' => [ 'is', 'isnot' ] ],
		];

		$entry_meta['eway_expiry'] = [
			'label'             => 'Card Expiry',
			'is_numeric'        => FALSE,
			'is_default_column' => FALSE,
			'filter'            => [ 'operators' => [ 'is', 'isnot' ] ],
		];

		return $entry_meta;
	}

	/**
	 * Adds card info meta to the entry if available and token is changed.
	 *
	 * @param $entry
	 * @param $original_entry
	 *
	 * @return mixed
	 */
	public function add_card_meta( $entry, $original_entry ) {
		// This hook should only during the post phase of the
		if ( empty( $entry['eway_token'] ) || ( $entry['eway_token'] === $original_entry['eway_token'] ) ) {
			return $entry;
		}

		if ( $this->customerTokenInfo ) {
			$cd                   = $this->customerTokenInfo->CardDetails;
			$entry['eway_card']   = $cd->Number;
			$entry['eway_expiry'] = $cd->ExpiryMonth . '/' . $cd->ExpiryYear;
		}

		return $entry;
	}

	/**
	 * Populates the available tokens for a given user with those retrieved from CiviCRM.
	 *
	 * @param $result
	 * @param $option
	 * @param \WP_User $user
	 *
	 * @return mixed
	 */
	public static function add_civicrm_tokens( $result, $option, ?WP_User $user = null ) {
		try {
			// TODO: Allow override using Contact ID + Checksum parameters
			$contact_id = UFMatch::get( FALSE )
			                     ->addWhere( 'domain_id.is_active', '=', TRUE )
			                     ->addWhere( 'uf_id', '=', $user->ID )
			                     ->execute()->first()['contact_id'];
			$tokens     = PaymentToken::get( TRUE )
			                          ->addWhere( 'contact_id', '=', $contact_id )
			                          ->addWhere( 'payment_processor_id.class_name', '=', 'au.com.agileware.ewayrecurring' );

			$tokens = $tokens->execute();
			$tokens = $tokens->indexBy( 'token' );

			foreach ( $tokens as $key => $paymentToken ) {
				if ( empty( $result[ $key ] ) ) {
					$result[ $key ] = [
						'token'   => $paymentToken['token'],
						'card'    => $paymentToken['masked_account_number'],
						'expiry'  => ! empty( $paymentToken['expiry_date'] ) ? date_create( $paymentToken['expiry_date'] )->format( 'm/y' ) : NULL,
						'updated' => strtotime( $paymentToken['created_date'] ),
					];
				}
			}
		} catch ( \API_Exception $e ) {
			// ...
		}

		return $result;
	}

	/**
	 * Registers filters for additional functionality
	 *
	 * @return void
	 */
	public function init() {
		parent::init();

		add_filter( 'gform_entry_pre_update', [ $this, 'add_card_meta' ], 10, 2 );
		add_filter( 'get_user_option_' . \webaware\gfewaypro\CustomerTokens::USER_OPTIONS_TOKENS,
			[ 'GFCiviCRM\eWAYProExtras', 'add_civicrm_tokens' ],
			10, 3 );
	}

}