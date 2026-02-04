/**
 * Fraud Detection utilities for WooCommerce.
 *
 * @package
 */

/**
 * Validates an email address format.
 *
 * @param {string} email - The email address to validate.
 * @return {boolean} True if valid, false otherwise.
 */
export function isValidEmail( email ) {
	if ( ! email || typeof email !== 'string' ) {
		return false;
	}
	const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	return emailRegex.test( email );
}

export default {
	isValidEmail,
};
