import { getNewPath } from '@woocommerce/navigation';

// Router location state that asks the rules page to open its "Create rule"
// drawer on arrival. The settings card's "Create rule" link sets it, so the
// merchant lands on the list the new rule will appear in. It is a one-time
// intent: the rules page clears it once the drawer is open.
export const OPEN_CREATE_RULE_STATE = { openCreateRule: true } as const;

export function shouldOpenCreateRule( state: unknown ): boolean {
	return (
		typeof state === 'object' &&
		state !== null &&
		( state as { openCreateRule?: unknown } ).openCreateRule === true
	);
}

export function getFraudProtectionRoute( path: string ) {
	const url = new URL(
		getNewPath(
			{ page: 'wc-settings', tab: 'woocommerce_fraud_protection' },
			path,
			{}
		),
		window.location.href
	);

	return `${ url.pathname }${ url.search }`;
}
