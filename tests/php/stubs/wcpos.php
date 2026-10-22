<?php
/**
 * Test stub for WooCommerce POS (WCPOS).
 *
 * Defines wcpos_request() in the global namespace, the WCPOS gateway base
 * class and the WCPOS token service, so that WcposCompat can recognize WCPOS
 * requests, gateways and cashier tokens.
 */

declare( strict_types=1 );

// This fixture provides classes and a function from the WCPOS plugin API.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden, Universal.Namespaces.OneDeclarationPerFile.MultipleFound

namespace WCPOS\WooCommercePOS\Payments {

	if ( ! class_exists( Abstract_POS_Gateway::class ) ) {
		/**
		 * Stub for the WCPOS gateway base class.
		 */
		abstract class Abstract_POS_Gateway extends \WC_Payment_Gateway {
		}
	}
}

namespace WCPOS\WooCommercePOS\Services {

	if ( ! class_exists( Auth::class ) ) {
		/**
		 * Stub for the WCPOS token service.
		 */
		class Auth {

			/**
			 * User IDs of the valid tokens, by token.
			 *
			 * @var array<string, int>
			 */
			public static array $valid_tokens = array();

			/**
			 * Get the instance.
			 *
			 * @return self
			 */
			public static function instance(): self {
				return new self();
			}

			/**
			 * Validate a token, returning its decoded payload like WCPOS does.
			 *
			 * @param string $token      The token.
			 * @param string $token_type The token type.
			 * @return object|\WP_Error
			 */
			public function validate_token( $token = '', $token_type = 'access' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Matches the WCPOS signature.
				if ( ! isset( self::$valid_tokens[ $token ] ) ) {
					return new \WP_Error( 'woocommmerce_pos_auth_invalid_token', 'Invalid token' );
				}

				return (object) array(
					'data' => (object) array(
						'user' => (object) array( 'id' => (string) self::$valid_tokens[ $token ] ),
					),
				);
			}
		}
	}
}

namespace {

	use WCPOS\WooCommercePOS\Payments\Abstract_POS_Gateway;

	/**
	 * Stub for the WCPOS cash gateway.
	 */
	class WCPOS_Cash_Gateway_Stub extends Abstract_POS_Gateway {

		/**
		 * Set the gateway ID.
		 */
		public function __construct() {
			$this->id = 'pos_cash';
		}
	}

	/**
	 * Controls the stubbed wcpos_request().
	 */
	class WCPOS_Request_Stub {

		/**
		 * Whether the current request is a WCPOS request.
		 *
		 * @var bool
		 */
		public static bool $is_pos_request = false;
	}

	if ( ! function_exists( 'wcpos_request' ) ) {
		/**
		 * Whether the current request comes from the POS.
		 *
		 * @param string $type The type of request to check for.
		 * @return bool
		 */
		function wcpos_request( $type = 'all' ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Universal.NamingConventions.NoReservedKeywordParameterNames.typeFound -- Matches the WCPOS signature.
			return WCPOS_Request_Stub::$is_pos_request;
		}
	}
}
