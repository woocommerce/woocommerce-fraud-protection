import type { ReactElement, ReactNode } from 'react';

import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Stack, Text } from '@wordpress/ui';

import { Spinner } from '../../ui-compat';

type AutomaticProtectionControlProps = {
	id: string;
	// The settings page and the enable drawer explain the setting differently.
	description: string;
	checked: boolean;
	disabled: boolean;
	isLoading?: boolean;
	onChange: ( value: boolean ) => void;
	descriptionRender?: ReactElement;
	children?: ReactNode;
};

export function AutomaticProtectionControl( {
	id,
	description,
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
				{ description }
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
								'Loading automatic blocking setting.',
								'woocommerce-fraud-protection'
							) }
						</span>
					</>
				) : (
					<CheckboxControl
						__nextHasNoMarginBottom
						id={ id }
						checked={ checked }
						disabled={ disabled }
						onChange={ onChange }
						label={ __(
							'Block flagged payment attempts automatically',
							'woocommerce-fraud-protection'
						) }
					/>
				) }
			</Stack>
		</>
	);
}
