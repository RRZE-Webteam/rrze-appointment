import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import type { AdminConfig, BookingsResponse, BookingView } from './types';

export function loadBookings(
	config: AdminConfig,
	view: BookingView,
	signal: AbortSignal
): Promise< BookingsResponse > {
	const url = new URL( config.url, window.location.href );
	url.searchParams.set( 'view', view );
	return apiFetch< BookingsResponse >( {
		url: url.href,
		headers: { 'X-WP-Nonce': config.nonce },
		credentials: 'same-origin',
		cache: 'no-store',
		signal,
	} );
}

export function cancelBooking(
	config: AdminConfig,
	slot: string,
	reason: string
): Promise< { message: string } > {
	return apiFetch< { message: string } >( {
		url: config.cancelUrl,
		method: 'POST',
		headers: { 'X-WP-Nonce': config.nonce },
		credentials: 'same-origin',
		cache: 'no-store',
		data: { slot, reason },
	} );
}

export function requestErrorMessage( error: unknown ): string {
	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof error.message === 'string'
	) {
		return error.message;
	}
	return __( 'The request failed. Please try again.', 'rrze-appointment' );
}

export function isAccessError( error: unknown ): boolean {
	if ( ! error || typeof error !== 'object' || ! ( 'data' in error ) ) {
		return false;
	}
	const data = error.data;
	return (
		!! data &&
		typeof data === 'object' &&
		'status' in data &&
		( data.status === 401 || data.status === 403 )
	);
}
