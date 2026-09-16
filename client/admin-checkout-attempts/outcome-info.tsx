import type { ReactNode } from 'react';
import { Popover } from '@wordpress/ui';
import { Icon, info } from '@wordpress/icons';

type OutcomeInfoProps = {
	ariaLabel: string;
	title: string;
	children: ReactNode;
};

export function OutcomeInfo( {
	ariaLabel,
	title,
	children,
}: OutcomeInfoProps ) {
	return (
		<Popover.Root>
			<Popover.Trigger
				openOnHover
				render={
					<button
						type="button"
						className="wc-fraud-protection-checkout-attempts__outcome-info"
						aria-label={ ariaLabel }
					>
						<Icon
							className="wc-fraud-protection-checkout-attempts__outcome-info-icon"
							icon={ info }
							size={ 24 }
							aria-hidden="true"
						/>
					</button>
				}
			/>
			<Popover.Popup
				className="wc-fraud-protection-checkout-attempts__outcome-popover"
				positioner={
					<Popover.Positioner side="bottom" sideOffset={ 8 } />
				}
			>
				<Popover.Title className="screen-reader-text">
					{ title }
				</Popover.Title>
				{ children }
			</Popover.Popup>
		</Popover.Root>
	);
}
