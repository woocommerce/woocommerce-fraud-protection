import { Badge } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

import { FlaggedChip } from './flagged-chip';
import { OutcomeInfo } from './outcome-info';
import type { Outcome } from './types';

type BadgeIntent =
	| 'high'
	| 'medium'
	| 'low'
	| 'stable'
	| 'informational'
	| 'draft'
	| 'none';

type OutcomeDefinition = {
	label: string;
	intent: BadgeIntent;
};

// The four summarized outcomes plus the plain allow, keyed by their REST value.
// Labels reuse the merchant-facing wording from the performance summary.
const OUTCOMES: Record< Outcome, OutcomeDefinition > = {
	allowed: {
		label: __( 'Allowed', 'woocommerce-fraud-protection' ),
		intent: 'stable',
	},
	allowed_by_rules: {
		label: __( 'Allowed by rules', 'woocommerce-fraud-protection' ),
		intent: 'stable',
	},
	flagged_by_fraud_prevention: {
		// Used for the Outcome filter option. The Outcome column itself renders an
		// "Allowed" badge plus a "Flagged" badge and an info popover (see
		// OutcomeBadge), since the attempt was allowed and "flagged" carries the
		// risk signal. The wording matches the settings performance summary's
		// "Flagged by fraud prevention".
		label: __( 'Allowed, flagged', 'woocommerce-fraud-protection' ),
		intent: 'medium',
	},
	blocked_automatically: {
		label: __( 'Blocked', 'woocommerce-fraud-protection' ),
		intent: 'high',
	},
	blocked_by_rules: {
		label: __( 'Blocked by rules', 'woocommerce-fraud-protection' ),
		intent: 'high',
	},
};

export function getOutcomeLabel( outcome: Outcome ): string {
	return OUTCOMES[ outcome ]?.label ?? outcome;
}

export function getOutcomeOptions(): Array< {
	value: Outcome;
	label: string;
} > {
	return ( Object.keys( OUTCOMES ) as Outcome[] ).map( ( value ) => ( {
		value,
		label: OUTCOMES[ value ].label,
	} ) );
}

export function OutcomeBadge( {
	outcome,
	automaticProtection = false,
	automaticProtectionEnabledAt = null,
	settingsUrl = '',
}: {
	outcome: Outcome;
	automaticProtection?: boolean;
	automaticProtectionEnabledAt?: string | null;
	settingsUrl?: string;
} ) {
	// A flagged-but-allowed attempt reads as an "Allowed" badge next to a
	// "Flagged" badge and an info icon whose tooltip explains it.
	if ( 'flagged_by_fraud_prevention' === outcome ) {
		return (
			<span className="wc-fraud-protection-checkout-attempts__status">
				<Badge intent={ OUTCOMES.allowed.intent }>
					{ OUTCOMES.allowed.label }
				</Badge>
				<FlaggedChip
					protectionOn={ automaticProtection }
					enabledAt={ automaticProtectionEnabledAt }
					settingsUrl={ settingsUrl }
				/>
			</span>
		);
	}

	const definition = OUTCOMES[ outcome ];

	if ( ! definition ) {
		return <>{ outcome }</>;
	}
	if ( 'blocked_automatically' === outcome ) {
		return (
			<span className="wc-fraud-protection-checkout-attempts__status">
				<Badge intent={ definition.intent }>{ definition.label }</Badge>
				<OutcomeInfo
					ariaLabel={ __(
						'Why was this blocked?',
						'woocommerce-fraud-protection'
					) }
					title={ __(
						'Why this checkout attempt was blocked',
						'woocommerce-fraud-protection'
					) }
				>
					{ __(
						'Blocked automatically by fraud prevention.',
						'woocommerce-fraud-protection'
					) }
				</OutcomeInfo>
			</span>
		);
	}

	return <Badge intent={ definition.intent }>{ definition.label }</Badge>;
}
