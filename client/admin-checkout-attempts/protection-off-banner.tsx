import { Notice } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

// Shown at the top of the checkout attempts list while automatic protection is
// off: fraud prevention is flagging risky attempts but not blocking them. The
// button opens the Fraud prevention settings in a new tab so the merchant can
// turn it on without losing their place in the list. It is not dismissable.
export function ProtectionOffBanner( {
	settingsUrl,
}: {
	settingsUrl: string;
} ) {
	return (
		<Notice.Root
			className="wc-fraud-protection-checkout-attempts__protection-banner"
			intent="info"
		>
			<Notice.Description>
				{ __(
					'Automatic protection is off. Fraud prevention is flagging risky attempts but not stopping them. Turn it on to block flagged attempts. Your rules keep working either way.',
					'woocommerce-fraud-protection'
				) }
			</Notice.Description>
			<Notice.Actions>
				<Notice.ActionButton
					// The action is a link (it navigates to settings), so it
					// renders as an <a>; tell Base UI this is intentionally not a
					// native <button>.
					nativeButton={ false }
					render={
						// A real link opening the settings in a new tab, so the
						// list stays open behind it. The label comes from the
						// button's children below.
						// eslint-disable-next-line jsx-a11y/anchor-has-content -- The link text is the ActionButton's children.
						<a
							href={ settingsUrl }
							target="_blank"
							rel="noopener noreferrer"
						/>
					}
				>
					{ __(
						'Enable automatic protection',
						'woocommerce-fraud-protection'
					) }
				</Notice.ActionButton>
			</Notice.Actions>
		</Notice.Root>
	);
}
