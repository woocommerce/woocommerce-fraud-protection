import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Card, Link as UiLink, Notice, Stack } from '@wordpress/ui';
import { Link } from 'react-router-dom';

import { getFraudProtectionRoute } from '../navigation';
import { AutomaticProtectionControl } from './automatic-protection-control';

type AutomaticProtectionCardProps = {
	checked: boolean;
	controlsDisabled: boolean;
	isLoading: boolean;
	onChange: ( value: boolean ) => void;
	flaggedByFraudPreventionCount: number;
	savedAutomaticProtection: boolean | null;
};

const checkoutAttemptsHref = getFraudProtectionRoute( '/checkout-attempts' );

export function AutomaticProtectionCard( {
	checked,
	controlsDisabled,
	isLoading,
	onChange,
	flaggedByFraudPreventionCount,
	savedAutomaticProtection,
}: AutomaticProtectionCardProps ) {
	const showNotice = ! isLoading && savedAutomaticProtection === false;
	const noticeText =
		0 === flaggedByFraudPreventionCount
			? __(
					'Automatic fraud prevention is off. We recommend turning it on.',
					'woocommerce-fraud-protection'
			  )
			: sprintf(
					/* translators: %d: Number of checkout attempts. The <a> tags link the count to the checkout attempts page. */
					_n(
						'<a>%d checkout attempt</a> was flagged as suspicious in the last 30 days but allowed because automatic fraud prevention is off. We recommend turning it on.',
						'<a>%d checkout attempts</a> were flagged as suspicious in the last 30 days but allowed because automatic fraud prevention is off. We recommend turning it on.',
						flaggedByFraudPreventionCount,
						'woocommerce-fraud-protection'
					),
					flaggedByFraudPreventionCount
			  );

	return (
		<Card.Root
			className="wc-fraud-protection-settings__card"
			render={ <section /> }
		>
			<Card.Header>
				<Card.Title render={ <h2 /> }>
					{ __( 'Fraud prevention', 'woocommerce-fraud-protection' ) }
				</Card.Title>
			</Card.Header>
			<Card.Content>
				<Stack direction="column" gap="xl">
					<AutomaticProtectionControl
						id="automatic-protection-checkbox"
						checked={ checked }
						disabled={ controlsDisabled }
						isLoading={ isLoading }
						onChange={ onChange }
					/>
					{ showNotice && (
						<Notice.Root intent="warning">
							<Notice.Description>
								{ createInterpolateElement( noticeText, {
									a: (
										<UiLink
											tone="neutral"
											render={
												<Link
													to={ checkoutAttemptsHref }
												/>
											}
										/>
									),
								} ) }
							</Notice.Description>
						</Notice.Root>
					) }
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}
