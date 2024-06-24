<?php
/**
 * Custom Exception class for GF CiviCRM
 */

class GFCiviCRM_Exception extends Exception
{
    public function __construct($message, $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * NOTE: 
     * Invalid Entities and Actions will *sometimes* print out as hashed codes when the cache is on for wpcmrf_api().
     * This is because failed API calls can be cached without an Entity or Action in the $call.
     * When creating post data for the cURL request, these are then set to null and hashed.
     * 
     * Fixing this will depend on this issue in CMRF: https://github.com/CiviMRF/CMRF_Abstract_Core/issues/24
     */
    public function getErrorMessage( $message = '', $include_trace = false ): string {
        $error_log = $message . " " . $this->getMessage() . " on line " . $this->getLine() . " in " . $this->getFile();
        if ( $include_trace ) {
            $error_log .= "\n" . $this->getTraceAsString();
        }
        return $error_log;
    }

    public function logErrorMessage( $message = '', $include_trace = false ) {
        if ( WP_DEBUG && WP_DEBUG_LOG ) {
            error_log( print_r( $this->getErrorMessage( $message, $include_trace ), true ) );
        } else if ( WP_DEBUG && !WP_DEBUG_LOG ) {
            error_log( print_r( $this->getErrorMessage( $message, $include_trace ) ) );
        }
    }

}