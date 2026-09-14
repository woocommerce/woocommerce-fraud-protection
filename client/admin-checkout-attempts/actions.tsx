import { __ } from '@wordpress/i18n';
import type { Action } from '@wordpress/dataviews';

import type { CheckoutAttemptsConfig, Session } from './types';

const hasEmail = ( item: Session ): boolean => Boolean( item.email );
const hasIp = ( item: Session ): boolean => Boolean( item.ip );

// The rule actions have no destination yet: rule management ships separately,
// so they render as labels and perform no action when selected.
const noop = (): void => {};

/**
 * Build the per-row actions shown in the checkout attempts list.
 *
 * For a flagged-but-allowed attempt, while automatic fraud prevention is off,
 * the menu leads with a shortcut that opens the enable drawer. The remaining
 * actions follow the outcome table: a value with no matching rule offers the
 * opposite of the enforced result (block an allowed attempt, allow a blocked
 * one), and a value with a rule offers edit and delete for that rule.
 *
 * @param config   The page configuration (automatic-protection state).
 * @param onEnable Opens the "enable fraud prevention" drawer.
 */
export function buildActions(
	config: CheckoutAttemptsConfig,
	onEnable: () => void
): Action< Session >[] {
	return [
		{
			id: 'enable-automatic-protection',
			// DataViews renders a menu item's `label` as its children (through
			// Menu.ItemLabel), so a React node renders correctly even though the
			// public type is a string — it must stay a function, since DataViews
			// invokes a non-string label. This lets the one item carry the
			// design's accent styling while the rest of the menu stays native;
			// selecting it still runs `callback` below.
			label: ( () => (
				<span className="wc-fraud-protection-checkout-attempts__menu-link">
					{ __(
						'Turn on automatic fraud prevention',
						'woocommerce-fraud-protection'
					) }
				</span>
			) ) as unknown as ( items: Session[] ) => string,
			isEligible: ( item ) =>
				! config.automaticProtection &&
				item.outcome === 'flagged_by_fraud_prevention',
			// Open the enable drawer, so the merchant keeps the checkout attempts
			// list open behind it.
			callback: () => onEnable(),
		},
		{
			id: 'email-block',
			label: __(
				'Block this email address',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) =>
				hasEmail( item ) &&
				! item.rules.email &&
				item.final_status === 'allowed',
			callback: noop,
		},
		{
			id: 'email-allow',
			label: __(
				'Allow this email address',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) =>
				hasEmail( item ) &&
				! item.rules.email &&
				item.final_status === 'blocked',
			callback: noop,
		},
		{
			id: 'email-edit',
			label: __(
				'Edit email address rule',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) =>
				hasEmail( item ) && Boolean( item.rules.email ),
			callback: noop,
		},
		{
			id: 'email-delete',
			label: __(
				'Delete email address rule',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) =>
				hasEmail( item ) && Boolean( item.rules.email ),
			callback: noop,
		},
		{
			id: 'ip-block',
			label: __(
				'Block this IP address',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) =>
				hasIp( item ) &&
				! item.rules.ip &&
				item.final_status === 'allowed',
			callback: noop,
		},
		{
			id: 'ip-allow',
			label: __(
				'Allow this IP address',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) =>
				hasIp( item ) &&
				! item.rules.ip &&
				item.final_status === 'blocked',
			callback: noop,
		},
		{
			id: 'ip-edit',
			label: __( 'Edit IP address rule', 'woocommerce-fraud-protection' ),
			isEligible: ( item ) => hasIp( item ) && Boolean( item.rules.ip ),
			callback: noop,
		},
		{
			id: 'ip-delete',
			label: __(
				'Delete IP address rule',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) => hasIp( item ) && Boolean( item.rules.ip ),
			callback: noop,
		},
	];
}
