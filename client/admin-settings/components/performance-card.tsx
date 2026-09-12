import {
	Card,
	Icon,
	LinkButton,
	Skeleton,
	Stack,
	Text,
	VisuallyHidden,
} from '@wordpress/ui';
import { caution } from '@wordpress/icons';
import { __ } from '@wordpress/i18n';
import { Link } from 'react-router-dom';

import type { Performance } from '../data/store';
import { getFraudProtectionRoute } from '../navigation';

type PerformanceCardProps = {
	automaticProtection: boolean;
	isLoading: boolean;
	performance: Performance | null;
};

const metrics: Array< {
	key: keyof Performance;
	label: string;
} > = [
	{
		key: 'flagged_by_fraud_prevention',
		label: __(
			'Flagged by fraud prevention',
			'woocommerce-fraud-protection'
		),
	},
	{
		key: 'blocked_automatically',
		label: __( 'Blocked automatically', 'woocommerce-fraud-protection' ),
	},
	{
		key: 'allowed_by_rules',
		label: __( 'Allowed by rules', 'woocommerce-fraud-protection' ),
	},
	{
		key: 'blocked_by_rules',
		label: __( 'Blocked by rules', 'woocommerce-fraud-protection' ),
	},
];

const checkoutAttemptsHref = getFraudProtectionRoute( '/checkout-attempts' );

export function PerformanceCard( {
	automaticProtection,
	isLoading,
	performance,
}: PerformanceCardProps ) {
	const visibleMetrics = automaticProtection
		? metrics.filter(
				( metric ) => metric.key !== 'flagged_by_fraud_prevention'
		  )
		: metrics;

	return (
		<Card.Root
			className="wc-fraud-protection-settings__card"
			render={ <section /> }
		>
			<Card.Header>
				<Card.Title render={ <h2 /> }>
					{ __( 'Performance', 'woocommerce-fraud-protection' ) }
				</Card.Title>
			</Card.Header>
			<Card.Content className="wc-fraud-protection-settings__performance-content">
				<Stack direction="column" gap="xl">
					<Text
						className="wc-fraud-protection-settings__description"
						variant="body-md"
						render={ <p /> }
					>
						{ __(
							'See how fraud prevention is evaluating recent checkout activity.',
							'woocommerce-fraud-protection'
						) }
					</Text>
					<Stack direction="column" gap="sm">
						<Text
							className="wc-fraud-protection-settings__performance-period"
							variant="heading-md"
							render={ <p /> }
						>
							{ __(
								'Last 30 days',
								'woocommerce-fraud-protection'
							) }
						</Text>
						<dl
							className="wc-fraud-protection-settings__performance-metrics"
							aria-busy={ isLoading }
						>
							{ visibleMetrics.map( ( metric ) => (
								<div
									className="wc-fraud-protection-settings__performance-metric"
									key={ metric.key }
								>
									<Text
										variant="heading-sm"
										render={ <dt /> }
									>
										{ metric.label }
									</Text>
									<Text
										className="wc-fraud-protection-settings__performance-value"
										variant="body-lg"
										render={ <dd /> }
									>
										{ metric.key ===
											'flagged_by_fraud_prevention' &&
											! isLoading &&
											( performance?.[ metric.key ] ??
												0 ) > 0 && (
												<Icon
													className="wc-fraud-protection-settings__performance-caution-icon"
													icon={ caution }
												/>
											) }
										{ isLoading ? (
											<Skeleton className="wc-fraud-protection-settings__performance-skeleton" />
										) : (
											performance?.[ metric.key ] ?? '—'
										) }
									</Text>
								</div>
							) ) }
						</dl>
						{ isLoading && (
							<VisuallyHidden role="status">
								{ __(
									'Loading performance results.',
									'woocommerce-fraud-protection'
								) }
							</VisuallyHidden>
						) }
					</Stack>
					<Stack direction="row">
						<LinkButton
							variant="minimal"
							render={ <Link to={ checkoutAttemptsHref } /> }
						>
							{ __(
								'View checkout sessions',
								'woocommerce-fraud-protection'
							) }
						</LinkButton>
					</Stack>
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}
