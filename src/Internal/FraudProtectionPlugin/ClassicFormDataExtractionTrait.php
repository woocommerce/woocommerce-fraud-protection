<?php
/**
 * ClassicFormDataExtractionTrait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\ClassicFormRequestData;

defined( 'ABSPATH' ) || exit;

/**
 * Shared request data extraction for protectors and compat layers that read from $_POST.
 *
 * Delegates to the public {@see ClassicFormRequestData}, which extensions with their
 * own form handlers use directly. BlocksCheckoutProtector reads from WP_REST_Request
 * and does not use this trait.
 */
trait ClassicFormDataExtractionTrait {

	/**
	 * Get the submitted session ID from the POST data.
	 *
	 * @return mixed The submitted session ID, or an empty string if not found.
	 */
	private function get_submitted_session_id(): mixed {
		return ( new ClassicFormRequestData() )->get_submitted_session_id();
	}

	/**
	 * Build structured request data from form data.
	 *
	 * @param array $form_data Form data array — WC's $posted_data (shortcode checkout) or $_POST (add-payment-method).
	 * @return array Structured request data.
	 */
	private function build_request_data( array $form_data ): array {
		return ( new ClassicFormRequestData() )->build_request_data( $form_data );
	}
}
