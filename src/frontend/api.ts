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

/**
 * Loads the current visitor before opening the booking dialog. An HTML response
 * is an SSO hand-off page, so it replaces the current document and returns null.
 * @param returnTo URL to restore after the SSO flow finishes.
 */
export async function requestBooker(
	returnTo: string
): Promise< BookerResponse | null > {
	const response = await fetch(
		window.rrze_appointment?.restUrl ||
			'/wp-json/rrze/v2/appointment/booker',
		{
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { returnTo } ),
		}
	);
	const responseBody = await response.text();
	const trimmedResponseBody = responseBody.trim();

	if (
		trimmedResponseBody.startsWith( '<!DOCTYPE' ) ||
		trimmedResponseBody.startsWith( '<html' )
	) {
		document.open();
		document.write( responseBody );
		document.close();
		return null;
	}

	return JSON.parse( responseBody ) as BookerResponse;
}

export async function loadCurrentBooker(): Promise< Booker > {
	const requestData = new FormData();
	requestData.append( 'action', 'rrze_appointment_get_booker' );
	const response = await fetch(
		window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php',
		{ method: 'POST', body: requestData }
	);
	const bookerResponse = ( await response.json() ) as BookerAjaxResponse;
	return bookerResponse.success ? bookerResponse.data || {} : {};
}
