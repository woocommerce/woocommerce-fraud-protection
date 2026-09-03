import { createRoot } from '@wordpress/element';

import './data/store';
import { FraudProtectionSettingsPage } from './settings-page';
import './style.scss';

const mount = document.getElementById( 'wc-fraud-protection-settings' );

if ( mount ) {
	createRoot( mount ).render( <FraudProtectionSettingsPage /> );
}
