import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Text } from '@wordpress/ui';
import { getHistory } from '@woocommerce/navigation';
import {
	Link,
	Navigate,
	Route,
	Routes,
	unstable_HistoryRouter as HistoryRouter,
} from 'react-router-dom';

import './data/store';
import { getFraudProtectionRoute } from './navigation';
import { FraudProtectionSettingsPage } from './settings-page';
import './style.scss';

const rootSettingsHref = getFraudProtectionRoute( '/' );

export function CheckoutAttemptsPage() {
	return (
		<Text variant="body-md" render={ <p /> }>
			{ __(
				'Hello from the checkout attempts page.',
				'woocommerce-fraud-protection'
			) }{ ' ' }
			<Link to={ rootSettingsHref }>
				{ __( 'Back', 'woocommerce-fraud-protection' ) }
			</Link>
		</Text>
	);
}

export function FraudProtectionAdminApp() {
	return (
		<HistoryRouter history={ getHistory() }>
			<Routes>
				<Route path="/" element={ <FraudProtectionSettingsPage /> } />
				<Route
					path="/checkout-attempts"
					element={ <CheckoutAttemptsPage /> }
				/>
				<Route
					path="*"
					element={ <Navigate to={ rootSettingsHref } replace /> }
				/>
			</Routes>
		</HistoryRouter>
	);
}

const mount = document.getElementById( 'wc-fraud-protection-settings' );

if ( mount ) {
	createRoot( mount ).render( <FraudProtectionAdminApp /> );
}
