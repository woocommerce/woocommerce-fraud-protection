/**
 * Tests for fraud detection utilities.
 *
 * @package WooCommerce\FraudProtection
 */

import { isValidEmail } from '../../assets/js/fraud-detection';

describe( 'mock test', () => {
	it( 'just a mock test', () => {
		expect( isValidEmail( 'test@example.com' ) ).toBe( true );
	} );
} );
