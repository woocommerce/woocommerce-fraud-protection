import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';
import { __ } from '@wordpress/i18n';
import type { Field } from '@wordpress/dataviews';

import { OutcomeBadge, getOutcomeOptions } from './outcomes';
import { RuleChip } from './rule-chip';
import type { CheckoutAttemptsConfig, Session } from './types';

const EMPTY = '—';

function formatRecordedAt( value: string ): string {
	// The value is a GMT time with no offset; mark it UTC so dateI18n renders
	// it in the site timezone.
	const formats = getDateSettings().formats;
	const format = formats.datetimeAbbreviated || formats.datetime;

	return dateI18n( format, `${ value }Z` );
}

export function getFields(
	config: CheckoutAttemptsConfig,
	isCompact = false
): Field< Session >[] {
	const paymentMethods = config.paymentMethods;
	const valueClass =
		'wc-fraud-protection-checkout-attempts__value' +
		( isCompact ? ' is-compact' : '' );

	return [
		{
			id: 'payment_method',
			label: __( 'Provider', 'woocommerce-fraud-protection' ),
			enableHiding: false,
			enableGlobalSearch: false,
			getValue: ( { item } ) => item.payment_method.id,
			render: ( { item } ) =>
				item.payment_method.title || item.payment_method.id || EMPTY,
			elements: paymentMethods.map( ( method ) => ( {
				value: method.id,
				label: method.title || method.id,
			} ) ),
			filterBy: { operators: [ 'isAny' ] },
		},
		{
			id: 'recorded_at',
			label: __( 'Date and time', 'woocommerce-fraud-protection' ),
			enableGlobalSearch: false,
			getValue: ( { item } ) => item.recorded_at_gmt,
			render: ( { item } ) => formatRecordedAt( item.recorded_at_gmt ),
			filterBy: false,
		},
		{
			id: 'email',
			label: __( 'Customer email', 'woocommerce-fraud-protection' ),
			getValue: ( { item } ) => item.email ?? '',
			render: ( { item } ) => {
				// In compact density the chip is icon-only and sits to the left
				// of the value; otherwise it wraps below the value.
				const chip = item.email ? (
					<RuleChip rule={ item.rules.email } compact={ isCompact } />
				) : null;

				return (
					<span className={ valueClass }>
						{ isCompact && chip }
						<span className="wc-fraud-protection-checkout-attempts__value-text">
							{ item.email || EMPTY }
						</span>
						{ ! isCompact && chip }
					</span>
				);
			},
			filterBy: false,
		},
		{
			id: 'ip',
			label: __( 'IP', 'woocommerce-fraud-protection' ),
			getValue: ( { item } ) => item.ip ?? '',
			render: ( { item } ) => {
				const chip = item.ip ? (
					<RuleChip rule={ item.rules.ip } compact={ isCompact } />
				) : null;

				return (
					<span className={ valueClass }>
						{ isCompact && chip }
						<span className="wc-fraud-protection-checkout-attempts__value-text">
							{ item.ip || EMPTY }
						</span>
						{ ! isCompact && chip }
					</span>
				);
			},
			filterBy: false,
		},
		{
			id: 'ip_country',
			label: __( 'IP location', 'woocommerce-fraud-protection' ),
			enableGlobalSearch: false,
			getValue: ( { item } ) => item.ip_country?.name ?? '',
			render: ( { item } ) => item.ip_country?.name || EMPTY,
			filterBy: false,
		},
		{
			id: 'billing_country',
			label: __( 'Billing address', 'woocommerce-fraud-protection' ),
			enableGlobalSearch: false,
			getValue: ( { item } ) => item.billing_address.country?.name ?? '',
			render: ( { item } ) => {
				const country = item.billing_address.country?.name ?? '';
				const locality = [
					item.billing_address.city,
					item.billing_address.postcode,
				]
					.filter( Boolean )
					.join( ', ' );

				if ( ! country && ! locality ) {
					return EMPTY;
				}

				return (
					<span className="wc-fraud-protection-checkout-attempts__billing">
						{ country && <span>{ country }</span> }
						{ locality && (
							<span className="wc-fraud-protection-checkout-attempts__billing-secondary">
								{ locality }
							</span>
						) }
					</span>
				);
			},
			filterBy: false,
		},
		{
			// Filter-only field (not shown as a column): whether an active
			// merchant rule currently targets the attempt's email or IP. A
			// single-select operator renders it as a radio-style filter.
			id: 'rules',
			label: __( 'Merchant rule', 'woocommerce-fraud-protection' ),
			enableSorting: false,
			enableGlobalSearch: false,
			getValue: ( { item } ) =>
				item.rules.email || item.rules.ip ? 'with' : 'without',
			render: ( { item } ) =>
				item.rules.email || item.rules.ip
					? __(
							'With matching rules',
							'woocommerce-fraud-protection'
					  )
					: __(
							'Without matching rules',
							'woocommerce-fraud-protection'
					  ),
			elements: [
				{
					value: 'with',
					label: __(
						'With matching rules',
						'woocommerce-fraud-protection'
					),
				},
				{
					value: 'without',
					label: __(
						'Without matching rules',
						'woocommerce-fraud-protection'
					),
				},
			],
			filterBy: { operators: [ 'is' ] },
		},
		{
			id: 'outcome',
			label: __( 'Outcome', 'woocommerce-fraud-protection' ),
			enableSorting: false,
			enableGlobalSearch: false,
			getValue: ( { item } ) => item.outcome,
			render: ( { item } ) => (
				<OutcomeBadge
					outcome={ item.outcome }
					automaticProtection={ config.automaticProtection }
					automaticProtectionEnabledAt={
						config.automaticProtectionEnabledAt
					}
					settingsUrl={ config.settingsUrl }
				/>
			),
			elements: getOutcomeOptions(),
			filterBy: { operators: [ 'isAny' ] },
		},
	];
}
