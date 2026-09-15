import type { ReactElement, ReactNode } from 'react';

import { __ } from '@wordpress/i18n';
import { Checkbox, Spinner, Stack, Text } from '@wordpress/ui';

type AutomaticProtectionControlProps = {
	id: string;
	checked: boolean;
	disabled: boolean;
	isLoading?: boolean;
	onChange: ( value: boolean ) => void;
	descriptionRender?: ReactElement;
	children?: ReactNode;
};

export function AutomaticProtectionControl( {
	id,
	checked,
	disabled,
	isLoading = false,
	onChange,
	descriptionRender = <p />,
	children,
}: AutomaticProtectionControlProps ) {
	return (
		<>
			<Text
				className="wc-fraud-protection-settings__description"
				variant="body-md"
				render={ descriptionRender }
			>
				{ __(
					'Fraud prevention scans checkout attempts for potentially automated or malicious shopper behavior. Flagged checkout attempts are recorded by default and are only blocked when automatic blocking is turned on.',
					'woocommerce-fraud-protection'
				) }
			</Text>
			{ children }
			<Stack
				className="wc-fraud-protection-settings__control"
				direction="row"
				align="center"
				gap="sm"
			>
				{ isLoading ? (
					<>
						<Spinner />
						<span className="screen-reader-text" role="status">
							{ __(
								'Loading automatic fraud prevention setting.',
								'woocommerce-fraud-protection'
							) }
						</span>
					</>
				) : (
					<>
						<Checkbox
							id={ id }
							checked={ checked }
							disabled={ disabled }
							onCheckedChange={ onChange }
						/>
						<label htmlFor={ id }>
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
		</>
	);
}
