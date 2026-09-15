import { useUserPreferences } from '@woocommerce/data';
import { Notice } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

const BANNER_DISMISSED_PREFERENCE =
	'fraud_protection_checkout_attempts_banner_dismissed';

type CheckoutAttemptsPreferences = ReturnType< typeof useUserPreferences > & {
	fraud_protection_checkout_attempts_banner_dismissed?: string;
};

export function ProtectionOffBanner( { onEnable }: { onEnable: () => void } ) {
	const preferences = useUserPreferences() as CheckoutAttemptsPreferences;
	const isDismissed = preferences[ BANNER_DISMISSED_PREFERENCE ] === 'yes';

	if ( preferences.isRequesting || isDismissed ) {
		return null;
	}

	const dismiss = () => {
		void preferences.updateUserPreferences( {
			[ BANNER_DISMISSED_PREFERENCE ]: 'yes',
		} );
	};

	return (
		<Notice.Root
			className="wc-fraud-protection-checkout-attempts__protection-banner"
			intent="info"
		>
			<Notice.Description>
				{ __(
					'Automatic fraud prevention is off. Fraud prevention is flagging risky attempts but not stopping them. Turn it on to block flagged attempts. Your rules keep working either way.',
					'woocommerce-fraud-protection'
				) }
			</Notice.Description>
			<Notice.Actions>
				<Notice.ActionButton onClick={ onEnable }>
					{ __(
						'Enable automatic fraud prevention',
						'woocommerce-fraud-protection'
					) }
				</Notice.ActionButton>
			</Notice.Actions>
			<Notice.CloseIcon
				label={ __(
					'Dismiss the automatic fraud prevention banner',
					'woocommerce-fraud-protection'
				) }
				onClick={ dismiss }
			/>
		</Notice.Root>
	);
}
