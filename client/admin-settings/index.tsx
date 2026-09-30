import { createRoot, useLayoutEffect, useRef } from '@wordpress/element';
import { getHistory } from '@woocommerce/navigation';
import type { ReactNode } from 'react';
import {
	Navigate,
	NavigationType,
	Route,
	Routes,
	unstable_HistoryRouter as HistoryRouter,
	useLocation,
	useNavigationType,
} from 'react-router-dom';

import './data/store';
import { CheckoutAttemptsPage } from '../admin-checkout-attempts/checkout-attempts-page';
import { getFraudProtectionRoute } from './navigation';
import { FraudProtectionSettingsPage } from './settings-page';
import { RulesPage } from './rules-page';
import './style.scss';

const MOUNT_ID = 'wc-fraud-protection-settings';
const rootSettingsHref = getFraudProtectionRoute( '/' );

// Wraps a page that shows its own breadcrumb header. While it is shown, the
// mount carries the drill-down class that hides the WooCommerce settings header
// and tabs. The server sets the class on the first load; this keeps it in sync
// on client-side navigation.
function DrillDownPage( { children }: { children: ReactNode } ) {
	useLayoutEffect( () => {
		const mount = document.getElementById( MOUNT_ID );
		mount?.classList.add( 'is-drill-down' );

		return () => mount?.classList.remove( 'is-drill-down' );
	}, [] );

	return <>{ children }</>;
}

// Client-side navigation keeps the window's scroll position, so a page opened
// from further down another page would show scrolled down. Start each newly
// opened page at the top. Only a route change counts: the lists also write
// their filters and pagination to the URL. Back and Forward are left to the
// browser, which restores the previous position.
function ScrollToTopOnRouteChange() {
	const { pathname } = useLocation();
	const navigationType = useNavigationType();
	const previousPathname = useRef( pathname );

	useLayoutEffect( () => {
		if ( pathname === previousPathname.current ) {
			return;
		}

		previousPathname.current = pathname;
		if ( navigationType !== NavigationType.Pop ) {
			window.scrollTo( 0, 0 );
		}
	}, [ pathname, navigationType ] );

	return null;
}

export function FraudProtectionAdminApp() {
	return (
		<HistoryRouter history={ getHistory() }>
			<ScrollToTopOnRouteChange />
			<Routes>
				<Route path="/" element={ <FraudProtectionSettingsPage /> } />
				<Route
					path="/rules"
					element={
						<DrillDownPage>
							<RulesPage />
						</DrillDownPage>
					}
				/>
				<Route
					path="/checkout-attempts"
					element={
						<DrillDownPage>
							<CheckoutAttemptsPage />
						</DrillDownPage>
					}
				/>
				<Route
					path="*"
					element={ <Navigate to={ rootSettingsHref } replace /> }
				/>
			</Routes>
		</HistoryRouter>
	);
}

const mount = document.getElementById( MOUNT_ID );

if ( mount ) {
	createRoot( mount ).render( <FraudProtectionAdminApp /> );
}
