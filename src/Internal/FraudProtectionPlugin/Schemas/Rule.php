<?php
/**
 * Rule schema class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object representing a merchant rule, as stored in a row
 * of the rules table.
 *
 * `action` is restricted to the actionable `FraudDecision` cases: an allow
 * rule always allows matching sessions, a block rule always blocks them.
 * `conditions` is the decoded JSON condition document, already normalized at
 * write time.
 */
class Rule {

	/**
	 * Private constructor — use the from_row() factory.
	 *
	 * @param int                  $id                The rule id.
	 * @param FraudDecision        $action            The action applied when the rule matches.
	 * @param RuleStatus           $status            The rule lifecycle status.
	 * @param int                  $position          The evaluation order, lower first.
	 * @param array<string, mixed> $conditions        The decoded condition document.
	 * @param ?array               $action_meta       Decoded action parameters, if any.
	 * @param ?array               $source_meta       Decoded creation-time context, if any.
	 * @param ?string              $source_session_id The Blackbox session the rule was created from, if any.
	 * @param string               $created_at        UTC creation timestamp (MySQL DATETIME format).
	 * @param ?int                 $created_by        Id of the user who created the rule, if known.
	 * @param ?string              $updated_at        UTC last-update timestamp, if ever updated.
	 * @param ?int                 $updated_by        Id of the user who last updated the rule, if known.
	 */
	private function __construct(
		public readonly int $id,
		public readonly FraudDecision $action,
		public readonly RuleStatus $status,
		public readonly int $position,
		public readonly array $conditions,
		public readonly ?array $action_meta,
		public readonly ?array $source_meta,
		public readonly ?string $source_session_id,
		public readonly string $created_at,
		public readonly ?int $created_by,
		public readonly ?string $updated_at,
		public readonly ?int $updated_by
	) {}

	/**
	 * Build a Rule from a rules table row.
	 *
	 * Returns null when the row cannot be interpreted as a rule (unknown
	 * action or status backing value, non-actionable action, or a conditions
	 * column that does not decode to a JSON object): callers treat such rows
	 * as non-matching instead of failing.
	 *
	 * @param array<string, mixed> $row The row as an associative array of column values.
	 * @return ?self The rule, or null when the row is not interpretable.
	 */
	public static function from_row( array $row ): ?self {
		$action = FraudDecision::tryFrom( (string) ( $row['action'] ?? '' ) );
		$status = RuleStatus::tryFrom( (string) ( $row['status'] ?? '' ) );

		if ( is_null( $action ) || is_null( $status ) || ! in_array( $action, FraudDecision::ACTIONABLE, true ) ) {
			return null;
		}

		$conditions = json_decode( (string) ( $row['conditions'] ?? '' ), true );
		if ( ! is_array( $conditions ) ) {
			return null;
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			$action,
			$status,
			(int) ( $row['position'] ?? 0 ),
			$conditions,
			self::decode_optional_json_object( $row['action_meta'] ?? null ),
			self::decode_optional_json_object( $row['source_meta'] ?? null ),
			self::nullable_string( $row['source_session_id'] ?? null ),
			(string) ( $row['created_at'] ?? '' ),
			self::nullable_id( $row['created_by'] ?? null ),
			self::nullable_string( $row['updated_at'] ?? null ),
			self::nullable_id( $row['updated_by'] ?? null )
		);
	}

	/**
	 * Decode a nullable JSON column value, mapping undecodable content to null.
	 *
	 * @param mixed $value The raw column value.
	 * @return ?array The decoded array, or null.
	 */
	private static function decode_optional_json_object( $value ): ?array {
		if ( is_null( $value ) || '' === $value ) {
			return null;
		}

		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Map a nullable string column value to a non-empty string or null.
	 *
	 * @param mixed $value The raw column value.
	 * @return ?string
	 */
	private static function nullable_string( $value ): ?string {
		return is_null( $value ) || '' === $value ? null : (string) $value;
	}

	/**
	 * Map a nullable numeric id column value to a positive int or null.
	 *
	 * @param mixed $value The raw column value.
	 * @return ?int
	 */
	private static function nullable_id( $value ): ?int {
		return is_null( $value ) || (int) $value <= 0 ? null : (int) $value;
	}
}
