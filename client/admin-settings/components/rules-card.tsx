import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, LinkButton, Stack, Text } from '@wordpress/ui';
import { Link } from 'react-router-dom';

import { useRuleFormDrawer } from '../hooks/use-rule-form-drawer';
import { getFraudProtectionRoute } from '../navigation';
import { RuleFormDrawer } from './rule-form-drawer';

const rulesHref = getFraudProtectionRoute( '/rules' );

export function RulesCard() {
	const { closeRuleForm, isOpen, openCreateRule, openEditRule, ruleId } =
		useRuleFormDrawer();

	return (
		<>
			<Card.Root
				className="wc-fraud-protection-settings__card"
				render={ <section /> }
			>
				<Card.Header render={ <Stack direction="column" gap="md" /> }>
					<Card.Title render={ <h2 /> }>
						{ __( 'Rules', 'woocommerce-fraud-protection' ) }
					</Card.Title>
					<Text
						className="wc-fraud-protection-settings__description"
						variant="body-md"
						render={ <p /> }
					>
						{ createInterpolateElement(
							__(
								'Create rules to always allow or block checkout attempts that match specific criteria. Rules take priority over automatic fraud prevention and allow rules override block rules. See our <a>best practices</a>.',
								'woocommerce-fraud-protection'
							),
							{
								a: (
									<a
										href="https://woocommerce.com/document/fraud-protection/"
										target="_blank"
										rel="noopener noreferrer"
									>
										<span />
									</a>
								),
							}
						) }
					</Text>
				</Card.Header>
				<Card.Content>
					<Stack direction="row" gap="sm">
						<Button
							variant="solid"
							size="compact"
							onClick={ openCreateRule }
						>
							{ __(
								'Create rule',
								'woocommerce-fraud-protection'
							) }
						</Button>
						<LinkButton
							variant="minimal"
							size="compact"
							render={ <Link to={ rulesHref } /> }
						>
							{ __(
								'View rules',
								'woocommerce-fraud-protection'
							) }
						</LinkButton>
					</Stack>
				</Card.Content>
			</Card.Root>
			<RuleFormDrawer
				open={ isOpen }
				ruleId={ ruleId }
				onClose={ closeRuleForm }
				onViewRule={ openEditRule }
			/>
		</>
	);
}
