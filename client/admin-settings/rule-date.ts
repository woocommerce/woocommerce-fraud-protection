import { dateI18n } from '@wordpress/date';

const browserTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;

export const formatRuleDate = ( timestamp: string ): string =>
	dateI18n( 'j M Y', timestamp, browserTimeZone );
