import { getNewPath } from '@woocommerce/navigation';

export function getFraudProtectionRoute(
	path: string,
	query: Record< string, string > = {}
) {
	const url = new URL(
		getNewPath(
			{
				page: 'wc-settings',
				tab: 'woocommerce_fraud_protection',
				...query,
			},
			path,
			{}
		),
		window.location.href
	);

	return `${ url.pathname }${ url.search }`;
}
