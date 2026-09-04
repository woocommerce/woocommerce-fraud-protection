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
		<Card.Root
			className="wc-fraud-protection-settings__card"
			render={ <section /> }
		>
			<Card.Header>
				<Card.Title render={ <h2 /> }>
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
							<>
								<Spinner />
								<span
									className="screen-reader-text"
									role="status"
								>
									{ __(
										'Loading automatic protection setting.',
										'woocommerce-fraud-protection'
									) }
								</span>
							</>
						) : (
							<>
								<Checkbox
									id="automatic-protection-checkbox"
									checked={ checked }
									disabled={ disabled }
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
				</Stack>
			</Card.Content>
		</Card.Root>
	);
}
