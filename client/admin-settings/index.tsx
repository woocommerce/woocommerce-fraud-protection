import { createRoot } from '@wordpress/element';
import { getHistory } from '@woocommerce/navigation';
import {
	Navigate,
	Route,
	Routes,
	unstable_HistoryRouter as HistoryRouter,
} from 'react-router-dom';

import './data/store';
import { CheckoutAttemptsPage } from '../admin-checkout-attempts/checkout-attempts-page';
import { getFraudProtectionRoute } from './navigation';
import { FraudProtectionSettingsPage } from './settings-page';
import './style.scss';

const rootSettingsHref = getFraudProtectionRoute( '/' );

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
