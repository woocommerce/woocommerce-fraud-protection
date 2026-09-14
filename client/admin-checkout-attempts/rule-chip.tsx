import { Tooltip } from '@wordpress/ui';
import { __, sprintf } from '@wordpress/i18n';

import { formatSiteDate } from './dates';
import type { RuleReference } from './types';

// A read-only indicator of the rule that currently targets a value (email or
// IP). It reflects the merchant's live rule configuration, not the historical
// outcome of the attempt, so it disappears as soon as the rule is deleted.
//
// The shape carries the meaning alongside the colour: a green check inside a
// circle for allow, a red prohibition circle for block. A tooltip states when
// the rule was created or, if it was ever edited, when it was last updated. In
// compact density only the icon shows (the label stays for assistive tech).

function AllowIcon() {
	return (
		<svg
			className="wc-fraud-protection-checkout-attempts__rule-chip-icon"
			viewBox="0 0 24 24"
			width="16"
			height="16"
			aria-hidden="true"
			focusable="false"
		>
			<circle
				cx="12"
				cy="12"
				r="9"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
			/>
			<path
				d="M7.8 12.4l2.8 2.8 5.6-6"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
		</svg>
	);
}

function BlockIcon() {
	return (
		<svg
			className="wc-fraud-protection-checkout-attempts__rule-chip-icon"
			viewBox="0 0 24 24"
			width="16"
			height="16"
			aria-hidden="true"
			focusable="false"
		>
			{ /* Prohibition sign: a circle with a diagonal slash. */ }
			<circle
				cx="12"
				cy="12"
				r="9"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
			/>
			<path
				d="M6.3 6.3l11.4 11.4"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
			/>
		</svg>
	);
}

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
	// was never edited shows its creation date. In compact density the visible
	// label is hidden, so the tooltip leads with "Allow"/"Block" instead.
	const updated = null !== rule.updated_at;
	const date = formatSiteDate( rule.updated_at ?? rule.created_at );

	let template;
	if ( compact && updated && isAllow ) {
		// translators: %s is a date.
		template = __(
			'Allow rule updated %s',
			'woocommerce-fraud-protection'
		);
	} else if ( compact && updated ) {
		// translators: %s is a date.
		template = __(
			'Block rule updated %s',
			'woocommerce-fraud-protection'
		);
	} else if ( compact && isAllow ) {
		// translators: %s is a date.
		template = __(
			'Allow rule created %s',
			'woocommerce-fraud-protection'
		);
	} else if ( compact ) {
		// translators: %s is a date.
		template = __(
			'Block rule created %s',
			'woocommerce-fraud-protection'
		);
	} else if ( updated ) {
		// translators: %s is a date.
		template = __( 'Rule updated %s', 'woocommerce-fraud-protection' );
	} else {
		// translators: %s is a date.
		template = __( 'Rule created %s', 'woocommerce-fraud-protection' );
	}

	const tooltip = sprintf( template, date );

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
					<span className={ classes }>
						{ isAllow ? <AllowIcon /> : <BlockIcon /> }
						<span className="wc-fraud-protection-checkout-attempts__rule-chip-label">
							{ label }
						</span>
					</span>
				}
			/>
			<Tooltip.Popup>{ tooltip }</Tooltip.Popup>
		</Tooltip.Root>
	);
}
