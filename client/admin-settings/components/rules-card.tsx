import { createInterpolateElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, LinkButton, Stack, Text } from '@wordpress/ui';
import { Link } from 'react-router-dom';

import { getFraudProtectionRoute } from '../navigation';
import { RuleFormDrawer } from './rule-form-drawer';

const rulesHref = getFraudProtectionRoute( '/rules' );

export function RulesCard() {
	const [ isDrawerOpen, setIsDrawerOpen ] = useState( false );

	return (
		<>
			<Card.Root
				className="wc-fraud-protection-settings__card"
				render={ <section /> }
			>
				<Card.Header>
					<Card.Title render={ <h2 /> }>
						{ __( 'Rules', 'woocommerce-fraud-protection' ) }
					</Card.Title>
				</Card.Header>
				<Card.Content>
					<Stack direction="column" gap="xl">
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
						<Stack direction="row" gap="sm">
							<Button
								variant="solid"
								onClick={ () => setIsDrawerOpen( true ) }
							>
								{ __(
									'Create rule',
									'woocommerce-fraud-protection'
								) }
							</Button>
							<LinkButton
								variant="minimal"
								render={ <Link to={ rulesHref } /> }
							>
								{ __(
									'View rules',
									'woocommerce-fraud-protection'
								) }
							</LinkButton>
						</Stack>
					</Stack>
				</Card.Content>
			</Card.Root>
			<RuleFormDrawer
				open={ isDrawerOpen }
				onClose={ () => setIsDrawerOpen( false ) }
			/>
		</>
	);
}
