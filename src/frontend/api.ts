import type {
	Booker,
	BookerAjaxResponse,
	BookerResponse,
	BookingResponse,
} from './types';

export async function submitAppointment(
	bookingFormData: FormData
): Promise< BookingResponse > {
	const response = await fetch(
		window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php',
		{ method: 'POST', body: bookingFormData }
	);
	return response.json() as Promise< BookingResponse >;
}

/** Returns a localized message for an unavailable booking identity. */
export function bookerErrorMessage(): string {
	return (
		window.rrze_appointment?.i18n?.bookingDetailsError ||
		'Unable to load your booking details. Please reload the page and try again. If the problem persists, contact the website administrator.'
	);
}

function isBooker( value: unknown ): value is Booker {
	if ( ! value || typeof value !== 'object' ) {
		return false;
	}
	const booker = value as Booker;
	return (
		typeof booker.bookerEmail === 'string' &&
		booker.bookerEmail.trim() !== '' &&
		typeof booker.bookerName === 'string' &&
		booker.bookerName.trim() !== ''
	);
}

/**
 * Loads the current SSO identity or an explicit same-origin login URL.
 * @param returnTo URL to restore after the SSO flow finishes.
 */
export async function requestBooker(
	returnTo: string
): Promise< BookerResponse > {
	const response = await fetch(
		window.rrze_appointment?.restUrl ||
			'/wp-json/rrze/v2/appointment/booker',
		{
			method: 'POST',
			credentials: 'same-origin',
			mode: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { returnTo } ),
		}
	);
	if ( ! response.ok ) {
		throw new Error( bookerErrorMessage() );
	}
	const payload: unknown = await response.json();
	if ( ! payload || typeof payload !== 'object' ) {
		throw new Error( bookerErrorMessage() );
	}
	const bookerResponse = payload as BookerResponse;
	if (
		bookerResponse.error ||
		typeof bookerResponse.needsLogin !== 'boolean'
	) {
		throw new Error( bookerErrorMessage() );
	}
	if ( bookerResponse.needsLogin ) {
		if (
			typeof bookerResponse.loginUrl !== 'string' ||
			! bookerResponse.loginUrl.trim()
		) {
			throw new Error( bookerErrorMessage() );
		}
		const loginUrl = new URL(
			bookerResponse.loginUrl,
			window.location.href
		);
		if (
			loginUrl.origin !== window.location.origin ||
			loginUrl.username ||
			loginUrl.password
		) {
			throw new Error( bookerErrorMessage() );
		}
	} else if ( ! isBooker( bookerResponse.data ) ) {
		throw new Error( bookerErrorMessage() );
	}
	return bookerResponse;
}

export async function loadCurrentBooker(): Promise< Booker > {
	const requestData = new FormData();
	requestData.append( 'action', 'rrze_appointment_get_booker' );
	const response = await fetch(
		window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php',
		{
			method: 'POST',
			credentials: 'same-origin',
			mode: 'same-origin',
			cache: 'no-store',
			body: requestData,
		}
	);
	if ( ! response.ok ) {
		throw new Error( bookerErrorMessage() );
	}
	const payload: unknown = await response.json();
	if ( ! payload || typeof payload !== 'object' ) {
		throw new Error( bookerErrorMessage() );
	}
	const bookerResponse = payload as BookerAjaxResponse;
	if (
		bookerResponse.success !== true ||
		! isBooker( bookerResponse.data )
	) {
		throw new Error( bookerErrorMessage() );
	}
	return bookerResponse.data;
}
