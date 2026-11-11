<?php
/**
 * Exception with a stable error code.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Core;

class IntegrationException extends \RuntimeException {

	/** @var string */
	public $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $error_code Stable machine readable code.
	 * @param string $message    Message.
	 */
	public function __construct( $error_code, $message = '' ) {
		parent::__construct( $message ? $message : $error_code );
		$this->error_code = (string) $error_code;
	}
}
