import { Card, Skeleton, Stack, Text, VisuallyHidden } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

import type { Performance } from '../data/store';

type PerformanceCardProps = {
	isLoading: boolean;
	performance: Performance | null;
};

const metrics: Array< {
	key: keyof Performance;
	label: string;
} > = [
	{
		key: 'recommended_for_blocking',
		label: __( 'Recommended for blocking', 'woocommerce-fraud-protection' ),
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

export function PerformanceCard( {
	isLoading,
	performance,
}: PerformanceCardProps ) {
	return (
		<Card.Root
			className="wc-fraud-protection-settings__card wc-fraud-protection-settings__performance-card"
			render={ <section /> }
		>
			<Card.Header>
				<Card.Title render={ <h2 /> }>
					{ __( 'Performance', 'woocommerce-fraud-protection' ) }
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
							{ metrics.map( ( metric ) => (
								<div
									className="wc-fraud-protection-settings__performance-metric"
									key={ metric.key }
								>
									<dt>
										<Text variant="heading-sm">
											{ metric.label }
										</Text>
									</dt>
									<dd>
										{ isLoading ? (
											<Skeleton className="wc-fraud-protection-settings__performance-skeleton" />
										) : (
											<Text variant="body-lg">
												{ performance?.[ metric.key ] ??
													'—' }
											</Text>
										) }
									</dd>
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
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}
