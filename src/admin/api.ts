import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import type { AdminConfig, BookingsResponse, BookingView } from './types';

/** Loads authorized display data without browser caching. */
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

/** Submits a cancellation using the current WordPress and SSO sessions. */
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

/** Returns a server-provided safe error message or a generic fallback. */
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

/** Recognizes authorization failures, including an unavailable SSO integration. */
export function isAccessError( error: unknown ): boolean {
	if ( ! error || typeof error !== 'object' || ! ( 'data' in error ) ) {
		return false;
	}
	const data = error.data;
	if (
		'code' in error &&
		( error.code === 'rrze_appointment_sso_unavailable' ||
			error.code === 'rrze_appointment_access_unavailable' )
	) {
		return true;
	}
	return (
		!! data &&
		typeof data === 'object' &&
		'status' in data &&
		( data.status === 401 || data.status === 403 )
	);
}

/** Identifies the only error that an explicit SSO login can resolve directly. */
export function needsSsoLogin( error: unknown ): boolean {
	return (
		!! error &&
		typeof error === 'object' &&
		'code' in error &&
		error.code === 'rrze_appointment_sso_required'
	);
}
