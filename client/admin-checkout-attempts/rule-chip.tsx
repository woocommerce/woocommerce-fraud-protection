import { Icon, notAllowed, published } from '@wordpress/icons';
import { Tooltip } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';

import { formatDate } from './dates';
import type { RuleReference } from './types';

// A read-only indicator of the rule that currently targets a value (email or
// IP). It reflects the merchant's live rule configuration, not the historical
// outcome of the attempt, so it disappears as soon as the rule is deleted.
//
// The shape carries the meaning alongside the colour: a check (WordPress
// `published` glyph) for allow, a prohibition circle (`notAllowed`) for block. A
// tooltip states when the rule was created or, if it was ever edited, when it
// was last updated. In compact density only the icon shows.
//
// The trigger is a real button so it is reachable by keyboard, and it carries an
// aria-label with the full rule type and date so assistive tech announces the
// same information the tooltip shows sighted users (tooltip content alone is not
// reliably exposed to screen readers).

export function RuleChip( {
	rule,
	compact = false,
}: {
	rule: RuleReference;
	compact?: boolean;
} ) {
	if ( ! rule ) {
		return null;
	}

	const isAllow = 'allow' === rule.action;
	const label = isAllow
		? __( 'Allow rule', 'woocommerce-fraud-protection' )
		: __( 'Block rule', 'woocommerce-fraud-protection' );

	// A rule that was edited after creation shows its last-update date; one that
	// was never edited shows its creation date.
	const updated = null !== rule.updated_at;
	const date = formatDate( rule.updated_at ?? rule.created_at );

	// The accessible label always names the rule type and whether the date is a
	// creation or an update, so screen-reader users get the full context.
	let accessibleTemplate;
	if ( updated && isAllow ) {
		// translators: %s is a date.
		accessibleTemplate = __(
			'Allow rule updated %s',
			'woocommerce-fraud-protection'
		);
	} else if ( updated ) {
		// translators: %s is a date.
		accessibleTemplate = __(
			'Block rule updated %s',
			'woocommerce-fraud-protection'
		);
	} else if ( isAllow ) {
		// translators: %s is a date.
		accessibleTemplate = __(
			'Allow rule created %s',
			'woocommerce-fraud-protection'
		);
	} else {
		// translators: %s is a date.
		accessibleTemplate = __(
			'Block rule created %s',
			'woocommerce-fraud-protection'
		);
	}
	const accessibleLabel = sprintf( accessibleTemplate, date );

	// The visible tooltip is terser in the default density, where the visible
	// label already names the rule type; in compact density the label is hidden,
	// so it reuses the fuller accessible text.
	let visibleTemplate;
	if ( compact ) {
		visibleTemplate = accessibleTemplate;
	} else if ( updated ) {
		// translators: %s is a date.
		visibleTemplate = __(
			'Rule updated %s',
			'woocommerce-fraud-protection'
		);
	} else {
		// translators: %s is a date.
		visibleTemplate = __(
			'Rule created %s',
			'woocommerce-fraud-protection'
		);
	}
	const tooltip = sprintf( visibleTemplate, date );

	const classes = [
		'wc-fraud-protection-checkout-attempts__rule-chip',
		isAllow ? 'is-allow' : 'is-block',
		compact ? 'is-icon-only' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<Tooltip.Root>
			<Tooltip.Trigger
				render={
					<button
						type="button"
						className={ classes }
						aria-label={ accessibleLabel }
					>
						<Icon
							className="wc-fraud-protection-checkout-attempts__rule-chip-icon"
							icon={ isAllow ? published : notAllowed }
							size={ 16 }
							aria-hidden="true"
						/>
						<span
							className="wc-fraud-protection-checkout-attempts__rule-chip-label"
							aria-hidden="true"
						>
							{ label }
						</span>
					</button>
				}
			/>
			<Tooltip.Popup>{ tooltip }</Tooltip.Popup>
		</Tooltip.Root>
	);
}
