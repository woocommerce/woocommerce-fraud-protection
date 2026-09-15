import { dateI18n, getSettings as getDateSettings } from '@wordpress/date';

// The list's "Date and time" column renders the abbreviated datetime (short
// month, e.g. "Sep 14, 2026 3:00 pm"). Tooltips that show a date (rule
// created/updated, protection enabled) reuse the same date without the time, so
// the month styling stays consistent with the column.
function abbreviatedDateFormat(): string {
	const { formats } = getDateSettings();
	const datetime = formats.datetimeAbbreviated || formats.datetime;

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

// Format a GMT datetime (RFC3339 without offset) as a date, in the site timezone
// and the abbreviated (short-month) style used by the list. The trailing "Z"
// marks the value as UTC so the conversion is correct.
export function formatSiteDate( gmt: string ): string {
	return dateI18n( abbreviatedDateFormat(), `${ gmt }Z` );
}
