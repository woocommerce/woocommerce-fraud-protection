import { __ } from '@wordpress/i18n';
import type { Action } from '@wordpress/dataviews';

import type { CheckoutAttemptsConfig, Session } from './types';

const hasEmail = ( item: Session ): boolean => Boolean( item.email );
const hasIp = ( item: Session ): boolean => Boolean( item.ip );

export type RuleActionType = 'email' | 'ip';

type RuleActionCallbacks = {
	onCreateRule: ( item: Session, type: RuleActionType ) => void;
	onEditRule: ( ruleId: number ) => void;
	onDeleteRule: ( item: Session, type: RuleActionType ) => void;
};

const firstItem = ( items: Session[] ): Session | undefined => items[ 0 ];

/**
 * Build the per-row actions shown in the checkout attempts list.
 *
 * For a flagged-but-allowed attempt, while automatic fraud prevention is off,
 * the menu leads with a shortcut that opens the enable drawer. The remaining
 * actions follow the outcome table: a value with no matching rule offers the
 * opposite of the enforced result (block an allowed attempt, allow a blocked
 * one), and a value with a rule offers edit and delete for that rule.
 *
 * @param config    The page configuration (automatic-protection state).
 * @param onEnable  Opens the "enable fraud prevention" drawer.
 * @param callbacks Opens rule create, edit, and delete controls.
 */
export function buildActions(
	config: CheckoutAttemptsConfig,
	onEnable: () => void,
	callbacks?: RuleActionCallbacks
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
			callback: ( items ) => {
				const item = firstItem( items );
				if ( item ) {
					callbacks?.onCreateRule( item, 'email' );
				}
			},
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
			callback: ( items ) => {
				const item = firstItem( items );
				if ( item ) {
					callbacks?.onCreateRule( item, 'email' );
				}
			},
		},
		{
			id: 'email-edit',
			label: __(
				'Edit email address rule',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) =>
				hasEmail( item ) && Boolean( item.rules.email ),
			callback: ( items ) => {
				const ruleId = firstItem( items )?.rules.email?.id;
				if ( ruleId ) {
					callbacks?.onEditRule( ruleId );
				}
			},
		},
		{
			id: 'email-delete',
			label: __(
				'Delete email address rule',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) =>
				hasEmail( item ) && Boolean( item.rules.email ),
			callback: ( items ) => {
				const item = firstItem( items );
				if ( item ) {
					callbacks?.onDeleteRule( item, 'email' );
				}
			},
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
			callback: ( items ) => {
				const item = firstItem( items );
				if ( item ) {
					callbacks?.onCreateRule( item, 'ip' );
				}
			},
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
			callback: ( items ) => {
				const item = firstItem( items );
				if ( item ) {
					callbacks?.onCreateRule( item, 'ip' );
				}
			},
		},
		{
			id: 'ip-edit',
			label: __( 'Edit IP address rule', 'woocommerce-fraud-protection' ),
			isEligible: ( item ) => hasIp( item ) && Boolean( item.rules.ip ),
			callback: ( items ) => {
				const ruleId = firstItem( items )?.rules.ip?.id;
				if ( ruleId ) {
					callbacks?.onEditRule( ruleId );
				}
			},
		},
		{
			id: 'ip-delete',
			label: __(
				'Delete IP address rule',
				'woocommerce-fraud-protection'
			),
			isEligible: ( item ) => hasIp( item ) && Boolean( item.rules.ip ),
			callback: ( items ) => {
				const item = firstItem( items );
				if ( item ) {
					callbacks?.onDeleteRule( item, 'ip' );
				}
			},
		},
	];
}
