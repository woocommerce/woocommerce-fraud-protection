import { Badge, Popover } from '@wordpress/ui';
import { Icon, info } from '@wordpress/icons';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { formatSiteDate } from './dates';

// Shown in the Outcome cell next to the "Allowed" badge for an attempt that was
// flagged as suspicious but allowed: a "Flagged" badge and an info button whose
// popover explains it. While protection is off the popover links to turning it
// on; once on, it notes protection was off then and when it was enabled.
//
// This is a Popover, not a Tooltip: the explanation can contain a link, and a
// popover's content (and that link) is reachable by keyboard, touch, and screen
// readers, which a tooltip's is not.

export function FlaggedChip( {
	protectionOn,
	enabledAt,
	settingsUrl,
}: {
	protectionOn: boolean;
	enabledAt: string | null;
	settingsUrl: string;
} ) {
	let explanation;
	if ( protectionOn ) {
		explanation = enabledAt
			? sprintf(
					// translators: %s is the date automatic fraud prevention was enabled.
					__(
						'Flagged as suspicious but allowed because automatic fraud prevention was off. Enabled: %s',
						'woocommerce-fraud-protection'
					),
					formatSiteDate( enabledAt )
			  )
			: __(
					'Flagged as suspicious but allowed because automatic fraud prevention was off.',
					'woocommerce-fraud-protection'
			  );
	} else if ( settingsUrl ) {
		explanation = createInterpolateElement(
			__(
				'Flagged as suspicious but allowed because automatic fraud prevention is off. <a>Enable automatic fraud prevention</a>',
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

	return (
		<>
			<Badge intent="medium">
				{ __( 'Flagged', 'woocommerce-fraud-protection' ) }
			</Badge>
			<Popover.Root>
				<Popover.Trigger
					openOnHover
					render={
						<button
							type="button"
							className="wc-fraud-protection-checkout-attempts__flagged-info"
							aria-label={ __(
								'Why was this flagged?',
								'woocommerce-fraud-protection'
							) }
						>
							<Icon
								className="wc-fraud-protection-checkout-attempts__flagged-info-icon"
								icon={ info }
								size={ 24 }
								aria-hidden="true"
							/>
						</button>
					}
				/>
				<Popover.Popup
					className="wc-fraud-protection-checkout-attempts__flagged-popover"
					positioner={
						<Popover.Positioner side="bottom" sideOffset={ 8 } />
					}
				>
					{ /* Required for accessibility; the visible content is the
					     explanation, so the title is only for assistive tech. */ }
					<Popover.Title className="screen-reader-text">
						{ __(
							'Why this checkout attempt was flagged',
							'woocommerce-fraud-protection'
						) }
					</Popover.Title>
					{ explanation }
				</Popover.Popup>
			</Popover.Root>
		</>
	);
}
