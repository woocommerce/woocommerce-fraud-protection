import { dateI18n } from '@wordpress/date';

// Rule and checkout-attempt dates are shown in the merchant's own time zone.
export const browserTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

export const formatRuleDate = ( timestamp: string ): string =>
	dateI18n( 'j M Y', timestamp, browserTimeZone );

export const getUtcDateFilterBound = (
	value: string,
	endOfDay: boolean
): string | undefined => {
	const date = value.slice( 0, 10 );
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
		return undefined;
	}

	const [ year, month, day ] = date.split( '-' ).map( Number );
	const localBound = new Date(
		year,
		month - 1,
		day,
		endOfDay ? 23 : 0,
		endOfDay ? 59 : 0,
		endOfDay ? 59 : 0
	);
	if (
		localBound.getFullYear() !== year ||
		localBound.getMonth() !== month - 1 ||
		localBound.getDate() !== day
	) {
		return undefined;
	}

	return localBound.toISOString().replace( '.000Z', 'Z' );
};
