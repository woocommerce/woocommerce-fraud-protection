import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Card, Checkbox, Notice, Spinner, Stack, Text } from '@wordpress/ui';
import { Link } from 'react-router-dom';

import { getFraudProtectionRoute } from '../navigation';

type AutomaticProtectionCardProps = {
	checked: boolean;
	controlsDisabled: boolean;
	isLoading: boolean;
	isOptingOut: boolean;
	onChange: ( value: boolean ) => void;
	onOptOut: () => void;
	optedOut: boolean;
	flaggedByFraudPreventionCount: number;
	savedAutomaticProtection: boolean | null;
};

const checkoutAttemptsHref = getFraudProtectionRoute( '/checkout-attempts' );

export function AutomaticProtectionCard( {
	checked,
	controlsDisabled,
	isLoading,
	isOptingOut,
	onChange,
	onOptOut,
	optedOut,
	flaggedByFraudPreventionCount,
	savedAutomaticProtection,
}: AutomaticProtectionCardProps ) {
	const showNotice = ! isLoading && savedAutomaticProtection === false;
	let noticeText: string;
	if ( 0 === flaggedByFraudPreventionCount ) {
		noticeText = optedOut
			? __(
					'Automatic fraud prevention is off. We recommend turning it on.',
					'woocommerce-fraud-protection'
			  )
			: __(
					'Automatic fraud prevention is off. It will turn on by default on October 20. You can turn it on now using the setting above, or opt out of this change.',
					'woocommerce-fraud-protection'
			  );
	} else if ( optedOut ) {
		noticeText = sprintf(
			/* translators: %d: Number of checkout attempts. The <a> tags link the count to the checkout attempts page. */
			_n(
				'<a>%d checkout attempt</a> in the last 30 days is flagged as suspicious but allowed because automatic fraud prevention is off. We recommend turning it on.',
				'<a>%d checkout attempts</a> in the last 30 days are flagged as suspicious but allowed because automatic fraud prevention is off. We recommend turning it on.',
				flaggedByFraudPreventionCount,
				'woocommerce-fraud-protection'
			),
			flaggedByFraudPreventionCount
		);
	} else {
		noticeText = sprintf(
			/* translators: %d: Number of checkout attempts. The <a> tags link the count to the checkout attempts page. */
			_n(
				'<a>%d checkout attempt</a> was flagged in the last 30 days and allowed because automatic blocking is off. We will turn on blocking by default on October 20. You can turn it on now using the setting above, or opt out of this change.',
				'<a>%d checkout attempts</a> were flagged in the last 30 days and allowed because automatic blocking is off. We will turn on blocking by default on October 20. You can turn it on now using the setting above, or opt out of this change.',
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
					{ __( 'Fraud prevention', 'woocommerce-fraud-protection' ) }
				</Card.Title>
			</Card.Header>
			<Card.Content>
				<Stack direction="column" gap="xl">
					<Text
						className="wc-fraud-protection-settings__description"
						variant="body-md"
						render={ <p /> }
					>
						{ __(
							'Fraud prevention scans checkout attempts for potentially automated or malicious shopper behavior. Flagged checkout attempts are recorded by default and are only blocked when automatic blocking is turned on.',
							'woocommerce-fraud-protection'
						) }
					</Text>
					<Stack
						className="wc-fraud-protection-settings__control"
						direction="row"
						align="center"
						gap="sm"
					>
						{ isLoading ? (
							<>
								<Spinner />
								<span
									className="screen-reader-text"
									role="status"
								>
									{ __(
										'Loading automatic fraud prevention setting.',
										'woocommerce-fraud-protection'
									) }
								</span>
							</>
						) : (
							<>
								<Checkbox
									id="automatic-protection-checkbox"
									checked={ checked }
									disabled={ controlsDisabled }
									onCheckedChange={ onChange }
								/>
								<label htmlFor="automatic-protection-checkbox">
									<Text variant="body-md">
										{ __(
											'Automatically block checkout attempts flagged by fraud prevention.',
											'woocommerce-fraud-protection'
										) }
									</Text>
								</label>
							</>
						) }
					</Stack>
					{ showNotice && (
						<Notice.Root
							key={ optedOut ? 'opted-out' : 'opt-out' }
							intent="warning"
						>
							<Notice.Description>
								{ createInterpolateElement( noticeText, {
									a: <Link to={ checkoutAttemptsHref } />,
								} ) }
							</Notice.Description>
							{ ! optedOut && (
								<Notice.Actions>
									<Notice.ActionButton
										variant="outline"
										disabled={ controlsDisabled }
										loading={ isOptingOut }
										onClick={ onOptOut }
									>
										{ __(
											'Opt out of automatic blocking',
											'woocommerce-fraud-protection'
										) }
									</Notice.ActionButton>
									<Notice.ActionLink
										render={
											<a
												href="https://woocommerce.com/document/fraud-protection/"
												target="_blank"
												rel="noopener noreferrer"
											>
												{ __(
													'Learn more',
													'woocommerce-fraud-protection'
												) }
											</a>
										}
									/>
								</Notice.Actions>
							) }
						</Notice.Root>
					) }
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}
