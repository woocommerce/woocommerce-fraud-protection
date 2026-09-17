import type { View } from '@wordpress/dataviews';
import { getHistory } from '@woocommerce/navigation';

import { getFraudProtectionRoute } from './admin-settings/navigation';

export function getFilterValue( view: View, field: string ): unknown {
	return ( view.filters ?? [] ).find( ( filter ) => filter.field === field )
		?.value;
}

export function getScalarFilterValue( view: View, field: string ): string {
	const value = getFilterValue( view, field );
	return Array.isArray( value )
		? String( value[ 0 ] ?? '' )
		: String( value ?? '' );
}

export function getFilterValueList( view: View, field: string ): string[] {
	const value = getFilterValue( view, field );
	if ( value === undefined || value === null ) {
		return [];
	}
	return Array.isArray( value ) ? value.map( String ) : [ String( value ) ];
}

export function parsePositivePage( value: string | null ): number {
	if ( ! value || ! /^[1-9]\d*$/.test( value ) ) {
		return 1;
	}
	const page = Number( value );
	return Number.isSafeInteger( page ) ? page : 1;
}

export function setNonDefaultParam(
	params: URLSearchParams,
	key: string,
	value: string,
	defaultValue = ''
): void {
	if ( ! value || value === defaultValue ) {
		params.delete( key );
	} else {
		params.set( key, value );
	}
}

export function serializeCanonicalQuery( params: URLSearchParams ): string {
	const canonical = new URLSearchParams( params );
	canonical.sort();
	return canonical.toString();
}

export function getAdminListPath(
	path: string,
	params: URLSearchParams
): string {
	const query: Record< string, string > = {};
	params.forEach( ( value, key ) => {
		query[ key ] = value;
	} );
	return getFraudProtectionRoute( path, query );
}

export function writeAdminListPath(
	path: string,
	params: URLSearchParams,
	replace = false
): void {
	const history = getHistory();
	const adminPath = getAdminListPath( path, params );
	if ( replace ) {
		history.replace( adminPath );
	} else {
		history.push( adminPath );
	}
}

export function getCorrectedPage(
	currentPage: number,
	totalPages: number
): number | null {
	const target = totalPages >= 1 ? Math.min( currentPage, totalPages ) : 1;
	return target === currentPage ? null : target;
}
