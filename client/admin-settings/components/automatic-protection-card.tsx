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
	let noticeText;
	if ( 0 === flaggedByFraudPreventionCount ) {
		noticeText = __(
			'To help prevent fraudulent payments, we recommend turning on automatic blocking.',
			'woocommerce-fraud-protection'
		);
	} else if ( 1 === flaggedByFraudPreventionCount ) {
		// A separate string, not the singular form of the plural below: "once"
		// has no number, and some languages use the singular form for other
		// counts too.
		noticeText = __(
			'In the last 30 days, fraud prevention flagged suspicious activity <a>once</a> on your store. To help prevent fraudulent payments, we recommend turning on automatic blocking.',
			'woocommerce-fraud-protection'
		);
	} else {
		noticeText = sprintf(
			/* translators: %d: Number of times suspicious activity was flagged. The <a> tags link the count to the payment activity page. */
			_n(
				'In the last 30 days, fraud prevention flagged suspicious activity <a>%d time</a> on your store. To help prevent fraudulent payments, we recommend turning on automatic blocking.',
				'In the last 30 days, fraud prevention flagged suspicious activity <a>%d times</a> on your store. To help prevent fraudulent payments, we recommend turning on automatic blocking.',
				flaggedByFraudPreventionCount,
				'woocommerce-fraud-protection'
			),
			flaggedByFraudPreventionCount
		);
	}

	return (
		<Card.Root
			className="wc-fraud-protection-settings__card"
			render={ <section /> }
		>
			<Card.Header>
				<Card.Title render={ <h2 /> }>
					{ __(
						'Automatic blocking',
						'woocommerce-fraud-protection'
					) }
				</Card.Title>
			</Card.Header>
			<Card.Content>
				<Stack direction="column" gap="xl">
					<AutomaticProtectionControl
						id="automatic-protection-checkbox"
						description={ __(
							"Fraud prevention analyzes payment activity on your store for automated or malicious behavior. Automatic blocking stops payment attempts flagged as suspicious before they go through. If you turn this off, flagged activity will still be recorded, but payments won't be blocked.",
							'woocommerce-fraud-protection'
						) }
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
