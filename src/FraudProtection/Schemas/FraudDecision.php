<?php
/**
 * FraudDecision enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Fraud decision returned by the Blackbox verify API and applied to a session.
 *
 * Fail-open: any unrecognized or non-actionable verify response is treated as `Allow`.
 */
enum FraudDecision: string {

	/** Allow the session. */
	case Allow = 'allow';

	/** Block the session. */
	case Block = 'block';

	/** Challenge the session (defined but not acted on; falls open to Allow). */
	case Challenge = 'challenge';

	/**
	 * Decisions the plugin acts on. A verify response outside this set fails open to Allow.
	 *
	 * @var array<int, self>
	 */
	public const ACTIONABLE = array( self::Allow, self::Block );
}
