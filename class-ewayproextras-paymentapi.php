<?php

namespace GFCiviCRM\eWAYProExtras;

class PaymentAPI extends \webaware\gfewaypro\PaymentAPI {
	public function getPaymentSharedPage() {
		$request = json_decode( parent::getPaymentSharedPage() );

		if ( ! empty( $request->Customer->TokenCustomerID ) && $request->Method == static::METHOD_TOKEN_CREATE ) {
			$request->Method = static::METHOD_TOKEN_UPDATE;
		}

		return wp_json_encode( $request );
	}
}