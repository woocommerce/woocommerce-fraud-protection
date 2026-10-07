import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';

import { browserTimeZone } from '../admin-settings/rule-date';

// The list is read in the merchant's browser, so its dates render in the
// browser's time zone (as the rules list does), not the store's. The values are
// GMT datetimes (RFC3339 without offset); the trailing "Z" marks them as UTC so
// the conversion is correct.

// The "Date and time" column renders the abbreviated datetime (short month,
// e.g. "Sep 14, 2026 3:00 pm").
function abbreviatedDateTimeFormat(): string {
	const { formats } = getDateSettings();
	return formats.datetimeAbbreviated || formats.datetime;
}

// Tooltips that show a date (rule created/updated, protection enabled) reuse
// the column's date without the time, so the month styling stays consistent.
function abbreviatedDateFormat(): string {
	const { formats } = getDateSettings();
	const datetime = abbreviatedDateTimeFormat();

	// The abbreviated datetime is "<abbreviated date> <time>"; drop the trailing
	// time (and any separator left behind) so the result is the column's date
	// minus its time.
	if ( formats.time && datetime.endsWith( formats.time ) ) {
		return (
			datetime
				.slice( 0, -formats.time.length )
				.trim()
				.replace( /,$/, '' )
				.trim() || formats.date
		);
	}

	return formats.date;
}

export function formatDateTime( gmt: string ): string {
	return dateI18n(
		abbreviatedDateTimeFormat(),
		`${ gmt }Z`,
		browserTimeZone
	);
}

export function formatDate( gmt: string ): string {
	return dateI18n( abbreviatedDateFormat(), `${ gmt }Z`, browserTimeZone );
}
