import { Card, Checkbox, Spinner, Stack, Text } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

type AutomaticProtectionCardProps = {
	checked: boolean;
	disabled: boolean;
	isLoading: boolean;
	onChange: ( value: boolean ) => void;
};

export function AutomaticProtectionCard( {
	checked,
	disabled,
	isLoading,
	onChange,
}: AutomaticProtectionCardProps ) {
	return (
		<Card.Root render={ <section /> }>
			<Card.Header>
				<Card.Title
					// eslint-disable-next-line jsx-a11y/heading-has-content -- Card.Title injects its children into the render element.
					render={ <h2 /> }
				>
					{ __(
						'Automatic protection',
						'woocommerce-fraud-protection'
					) }
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
							<Spinner />
						) : (
							<>
								<Checkbox
									id="automatic-protection-checkbox"
									checked={ checked }
									disabled={ disabled }
									onCheckedChange={ onChange }
								/>
								<Text
									variant="body-md"
									render={
										// eslint-disable-next-line jsx-a11y/label-has-associated-control -- Text injects its children into the render element.
										<label htmlFor="automatic-protection-checkbox" />
									}
								>
									{ __(
										'Automatically block checkout attempts flagged by fraud prevention.',
										'woocommerce-fraud-protection'
									) }
								</Text>
							</>
						) }
					</Stack>
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}
