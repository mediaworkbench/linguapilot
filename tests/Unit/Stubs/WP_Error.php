<?php
/**
 * Minimal WP_Error for unit tests (Brain Monkey does not provide WordPress classes).
 *
 * @package MediaWorkbench\LinguaPilot
 */

// phpcs:disable -- Mirrors the WordPress core class name and API surface used by the plugin.

/**
 * Stand-in for WordPress' WP_Error with the methods the plugin uses.
 */
class WP_Error {

	/** @var string */
	private $code;

	/** @var string */
	private $message;

	/** @var mixed */
	private $data;

	/**
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param mixed  $data    Data.
	 */
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	/** @return string */
	public function get_error_code() {
		return $this->code;
	}

	/** @return string */
	public function get_error_message() {
		return $this->message;
	}

	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
}
