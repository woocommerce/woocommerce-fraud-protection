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
					// translators: %s is the date automatic fraud prevention was enabled.
					__(
						'Flagged as suspicious but allowed because automatic protection was off. Enabled %s.',
						'woocommerce-fraud-protection'
					),
					formatDate( enabledAt )
			  )
			: __(
					'Flagged as suspicious but allowed because automatic fraud prevention was off.',
					'woocommerce-fraud-protection'
			  );
	} else if ( settingsUrl ) {
		explanation = createInterpolateElement(
			__(
				'Flagged as suspicious but allowed because automatic fraud prevention is off. <a>Enable automatic fraud prevention</a>.',
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
			'Flagged as suspicious but allowed because automatic fraud prevention is off.',
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
					'Why this checkout attempt was flagged',
					'woocommerce-fraud-protection'
				) }
			>
				{ explanation }
			</OutcomeInfo>
		</>
	);
}
