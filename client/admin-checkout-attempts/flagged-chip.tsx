import { Badge, Tooltip } from '@wordpress/ui';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { formatSiteDate } from './dates';

// Shown in the Outcome cell next to the "Allowed" badge for an attempt that was
// flagged as suspicious but allowed: a "Flagged" badge and an info icon whose
// tooltip explains it. While protection is off the tooltip links to turning it
// on; once on, it notes protection was off then and when it was enabled.

function InfoIcon() {
	return (
		<svg
			className="wc-fraud-protection-checkout-attempts__flagged-info-icon"
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
			<circle cx="12" cy="8" r="1.15" fill="currentColor" />
			<path
				d="M12 11v6"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.6"
				strokeLinecap="round"
			/>
		</svg>
	);
}

export function FlaggedChip( {
	protectionOn,
	enabledAt,
	settingsUrl,
}: {
	protectionOn: boolean;
	enabledAt: string | null;
	settingsUrl: string;
} ) {
	let tooltip;
	if ( protectionOn ) {
		tooltip = enabledAt
			? sprintf(
					// translators: %s is the date automatic fraud prevention was enabled.
					__(
						'Flagged as suspicious but allowed because automatic fraud prevention was off. Enabled %s.',
						'woocommerce-fraud-protection'
					),
					formatSiteDate( enabledAt )
			  )
			: __(
					'Flagged as suspicious but allowed because automatic fraud prevention was off.',
					'woocommerce-fraud-protection'
			  );
	} else if ( settingsUrl ) {
		tooltip = createInterpolateElement(
			__(
				'Flagged as suspicious but allowed because automatic fraud prevention is off. <a>Enable automatic fraud prevention</a>',
				'woocommerce-fraud-protection'
			),
			{
				// eslint-disable-next-line jsx-a11y/anchor-has-content -- The link text is injected by createInterpolateElement.
				a: <a href={ settingsUrl } />,
			}
		);
	} else {
		tooltip = __(
			'Flagged as suspicious but allowed because automatic fraud prevention is off.',
			'woocommerce-fraud-protection'
		);
	}

	return (
		<>
			<Badge intent="medium">
				{ __( 'Flagged', 'woocommerce-fraud-protection' ) }
			</Badge>
			<Tooltip.Root>
				<Tooltip.Trigger
					render={
						<button
							type="button"
							className="wc-fraud-protection-checkout-attempts__flagged-info"
							aria-label={ __(
								'Why was this flagged?',
								'woocommerce-fraud-protection'
							) }
						>
							<InfoIcon />
						</button>
					}
				/>
				<Tooltip.Popup
					className="wc-fraud-protection-checkout-attempts__flagged-tooltip"
					positioner={
						<Tooltip.Positioner side="bottom" sideOffset={ 8 } />
					}
				>
					{ tooltip }
				</Tooltip.Popup>
			</Tooltip.Root>
		</>
	);
}
