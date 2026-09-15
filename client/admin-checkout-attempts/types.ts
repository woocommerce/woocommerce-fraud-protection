export type Outcome =
	| 'allowed'
	| 'allowed_by_rules'
	| 'blocked_by_rules'
	| 'blocked_automatically'
	| 'flagged_by_fraud_prevention';

export type FinalStatus = 'allowed' | 'blocked';

export type Country = {
	code: string;
	name: string;
} | null;

export type RuleReference = {
	id: number;
	action: 'allow' | 'block';
	// GMT datetimes (RFC3339 without offset). `updated_at` is null until the
	// rule is first edited.
	created_at: string;
	updated_at: string | null;
} | null;

export type Session = {
	id: number;
	recorded_at_gmt: string;
	payment_method: {
		id: string;
		title: string;
		icon: string | null;
	};
	email: string | null;
	ip: string | null;
	ip_country: Country;
	billing_address: {
		country: Country;
		city: string;
		postcode: string;
	};
	final_status: FinalStatus;
	outcome: Outcome;
	order_id: number | null;
	rules: {
		email: RuleReference;
		ip: RuleReference;
	};
};

export type PaymentMethodOption = {
	id: string;
	title: string;
};

export type CheckoutAttemptsConfig = {
	automaticProtection: boolean;
	// GMT datetime (RFC3339 without offset) protection was turned on, or null
	// when off or unknown.
	automaticProtectionEnabledAt: string | null;
	settingsUrl: string;
	// Provider filter options. These are not injected at page load; the list
	// route loads them on demand from the REST API (see usePaymentMethodOptions)
	// and merges them into the config it passes down.
	paymentMethods: PaymentMethodOption[];
};

const FALLBACK_CONFIG: CheckoutAttemptsConfig = {
	automaticProtection: false,
	automaticProtectionEnabledAt: null,
	settingsUrl: '',
	paymentMethods: [],
};

declare global {
	interface Window {
		wcFraudProtectionCheckoutAttempts?: Partial< CheckoutAttemptsConfig >;
	}
}

export function getConfig(): CheckoutAttemptsConfig {
	const config = window.wcFraudProtectionCheckoutAttempts ?? {};

	return {
		automaticProtection: config.automaticProtection ?? false,
		automaticProtectionEnabledAt:
			config.automaticProtectionEnabledAt ?? null,
		settingsUrl: config.settingsUrl ?? '',
		// Loaded on demand from the REST API, not injected at page load.
		paymentMethods: FALLBACK_CONFIG.paymentMethods,
	};
}
