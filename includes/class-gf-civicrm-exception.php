<?php
/**
 * Custom Exception class for GF CiviCRM
 */

class GFCiviCRM_Exception extends Exception
{
    public function __construct($message, $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrorLogMessage( $include_trace = false ): string {
        $error_log = $this->getMessage();
        if ( $include_trace ) {
            $error_log .= $this->getTraceAsString();
        }
        return $error_log;
    }

}