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
 * For a flagged-but-allowed attempt, while automatic protection is off, the menu
 * leads with a shortcut to turn it on. The remaining actions follow the outcome
 * table: a value with no matching rule offers the opposite of the enforced
 * result (block an allowed attempt, allow a blocked one), and a value with a
 * rule offers edit and delete for that rule.
 *
 * The shortcut carries the design's external-link styling (the admin accent
 * colour plus a ↗ arrow); see the note on its label below for how it renders
 * inside the otherwise-native DataViews menu.
 *
 * @param config The page configuration (automatic-protection state and the
 *               settings URL the shortcut navigates to).
 */
export function buildActions(
	config: CheckoutAttemptsConfig
): Action< Session >[] {
	return [
		{
			id: 'enable-automatic-protection',
			// DataViews renders a menu item's `label` as its children (through
			// Menu.ItemLabel), so a React node renders correctly even though the
			// public type is a string — it must stay a function, since DataViews
			// invokes a non-string label. This lets the one item carry the
			// design's external-link styling while the rest of the menu stays
			// native; selecting it still runs `callback` below.
			label: ( () => (
				<span className="wc-fraud-protection-checkout-attempts__menu-link">
					{ __(
						'Turn on automatic protection',
						'woocommerce-fraud-protection'
					) }
				</span>
			) ) as unknown as ( items: Session[] ) => string,
			isEligible: ( item ) =>
				! config.automaticProtection &&
				Boolean( config.settingsUrl ) &&
				item.outcome === 'flagged_by_fraud_prevention',
			// Open settings in a new tab (the label's ↗ signals this), so the
			// merchant keeps the checkout attempts list open behind it.
			callback: () => {
				window.open(
					config.settingsUrl,
					'_blank',
					'noopener,noreferrer'
				);
			},
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
