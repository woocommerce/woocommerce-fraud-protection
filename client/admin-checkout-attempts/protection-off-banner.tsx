import { Notice } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

// Shown at the top of the checkout attempts list while automatic fraud
// prevention is off: it is flagging risky attempts but not blocking them. The
// button opens the enable drawer so the merchant can turn it on without leaving
// the list. It is not dismissable.
export function ProtectionOffBanner( { onEnable }: { onEnable: () => void } ) {
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
		</Notice.Root>
	);
}
