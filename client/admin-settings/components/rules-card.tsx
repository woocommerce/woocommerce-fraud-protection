import { createInterpolateElement, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, Stack, Text } from '@wordpress/ui';
import { Link, useNavigate } from 'react-router-dom';

import { LinkButton } from '../../ui-compat';
import { useRuleFormDrawer } from '../hooks/use-rule-form-drawer';
import { getFraudProtectionRoute } from '../navigation';
import { RuleFormDrawer } from './rule-form-drawer';

const rulesHref = getFraudProtectionRoute( '/rules' );

export function RulesCard() {
	const { closeRuleForm, isOpen, openCreateRule, openEditRule, ruleId } =
		useRuleFormDrawer();
	const navigate = useNavigate();

	// A saved rule (a new one, or an existing duplicate the merchant went on to
	// edit from this drawer) leads to the rules list, where it now appears.
	// Cancelling, or a failed save, keeps the merchant on this page.
	const goToRules = useCallback( () => navigate( rulesHref ), [ navigate ] );

	return (
		<>
			<Card.Root
				className="wc-fraud-protection-settings__card"
				render={ <section /> }
			>
				<Card.Header render={ <Stack direction="column" gap="md" /> }>
					<Card.Title render={ <h2 /> }>
						{ __( 'Custom rules', 'woocommerce-fraud-protection' ) }
					</Card.Title>
					<Text
						className="wc-fraud-protection-settings__description"
						variant="body-md"
						render={ <p /> }
					>
						{ createInterpolateElement(
							__(
								"Always allow or block payment activity from specific IP or email addresses. Custom rules override automatic blocking, and still apply when it's off. If a payment attempt matches both an allow and a block rule, it's allowed. <a>Learn when to use custom rules</a>",
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
				onSuccess={ goToRules }
				onViewRule={ openEditRule }
			/>
		</>
	);
}
