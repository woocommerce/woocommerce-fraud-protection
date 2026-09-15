import { Notice } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

import { getSettingsConfig } from '../admin-settings/config';
import { useNoticeDismissed } from '../admin-settings/hooks/use-notice-dismissed';

// Per-user preference key for dismissing this banner. Stored in user meta
// `woocommerce_admin_<key>`; allow-listed server-side (see
// FraudProtectionController::add_user_data_fields).
const BANNER_DISMISSED_PREFERENCE =
	'fraud_protection_checkout_attempts_banner_dismissed';

// Shown at the top of the checkout attempts list while automatic fraud
// prevention is off: it is flagging risky attempts but not blocking them. The
// button opens the enable drawer so the merchant can turn it on without leaving
// the list. It can be dismissed per user, and the dismissal persists across
// reloads (stored the same way as the settings notice).
export function ProtectionOffBanner( { onEnable }: { onEnable: () => void } ) {
	const { isDismissed, dismiss } = useNoticeDismissed(
		BANNER_DISMISSED_PREFERENCE,
		getSettingsConfig().checkoutAttemptsBannerDismissed
	);

	if ( isDismissed ) {
		return null;
	}

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
