import { Badge } from '@wordpress/ui';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { formatDate } from './dates';
import { OutcomeInfo } from './outcome-info';

// Shown in the Outcome cell next to the "Allowed" badge for an attempt that was
// flagged as suspicious but allowed: a "Flagged" badge and an info button whose
// popover explains it. While protection is off the popover links to turning it
// on; once on, it notes protection was off then and when it was enabled.
//
// This is a Popover, not a Tooltip: the explanation can contain a link, and a
// popover's content (and that link) is reachable by keyboard, touch, and screen
// readers, which a tooltip's is not.

type FlaggedChipProps = {
	protectionOn: boolean;
	enabledAt: string | null;
	settingsUrl: string;
};

export function getFlaggedExplanation( {
	protectionOn,
	enabledAt,
	settingsUrl,
}: FlaggedChipProps ) {
	let explanation;
	if ( protectionOn ) {
		explanation = enabledAt
			? sprintf(
					// translators: %s is the date automatic blocking was turned on.
					__(
						"Flagged but not blocked because automatic blocking was off at the time. It's been active since %s.",
						'woocommerce-fraud-protection'
					),
					formatDate( enabledAt )
			  )
			: __(
					'Flagged but not blocked because automatic blocking was off at the time.',
					'woocommerce-fraud-protection'
			  );
	} else if ( settingsUrl ) {
		explanation = createInterpolateElement(
			__(
				'Flagged but not blocked because automatic blocking is off. <a>Turn on automatic blocking</a>',
				'woocommerce-fraud-protection'
			),
			{
				a: (
					// eslint-disable-next-line jsx-a11y/anchor-has-content -- The link text is injected by createInterpolateElement.
					<a
						href={ settingsUrl }
						target="_blank"
						rel="noopener noreferrer"
					/>
				),
			}
		);
	} else {
		explanation = __(
			'Flagged but not blocked because automatic blocking is off.',
			'woocommerce-fraud-protection'
		);
	}

	return explanation;
}

export function FlaggedChip( props: FlaggedChipProps ) {
	const explanation = getFlaggedExplanation( props );

	return (
		<>
			<Badge intent="medium">
				{ __( 'Flagged', 'woocommerce-fraud-protection' ) }
			</Badge>
			<OutcomeInfo
				ariaLabel={ __(
					'Why was this flagged?',
					'woocommerce-fraud-protection'
				) }
				title={ __(
					'Why this payment attempt was flagged',
					'woocommerce-fraud-protection'
				) }
			>
				{ explanation }
			</OutcomeInfo>
		</>
	);
}
