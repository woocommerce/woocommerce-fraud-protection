import {
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Spinner,
} from '@wordpress/components';
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
		<Card className="wc-fraud-protection-settings__card">
			<CardHeader
				className="wc-fraud-protection-settings__header"
				isBorderless
				size="none"
			>
				<div>
					<h2>
						{ __(
							'Automatic protection',
							'woocommerce-fraud-protection'
						) }
					</h2>
					<p>
						{ __(
							'Fraud prevention scans checkout attempts for potentially automated or malicious shopper behavior. Flagged checkout attempts are recorded by default and are only blocked when automatic blocking is turned on.',
							'woocommerce-fraud-protection'
						) }
					</p>
				</div>
			</CardHeader>
			<CardBody
				className="wc-fraud-protection-settings__body"
				size="none"
			>
				{ isLoading ? (
					<Spinner />
				) : (
					<CheckboxControl
						label={ __(
							'Automatically block checkout attempts flagged by fraud prevention.',
							'woocommerce-fraud-protection'
						) }
						checked={ checked }
						disabled={ disabled }
						onChange={ onChange }
					/>
				) }
			</CardBody>
		</Card>
	);
}
