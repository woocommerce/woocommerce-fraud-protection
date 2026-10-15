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
					"Automatic blocking is off, so payments flagged by fraud prevention won't be blocked. Turn it on to help prevent fraud. Any custom rules you've set apply either way.",
					'woocommerce-fraud-protection'
				) }
			</Notice.Description>
			<Notice.Actions>
				<Notice.ActionButton onClick={ onEnable }>
					{ __(
						'Turn on automatic blocking',
						'woocommerce-fraud-protection'
					) }
				</Notice.ActionButton>
			</Notice.Actions>
			<Notice.CloseIcon
				label={ __(
					'Dismiss the automatic blocking banner',
					'woocommerce-fraud-protection'
				) }
				onClick={ dismiss }
			/>
		</Notice.Root>
	);
}
