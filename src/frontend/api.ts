import type {
	Booker,
	BookerAjaxResponse,
	BookerResponse,
	BookingResponse,
} from './types';

export async function submitAppointment(
	data: FormData
): Promise< BookingResponse > {
	const response = await fetch(
		window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php',
		{ method: 'POST', body: data }
	);
	return response.json() as Promise< BookingResponse >;
}

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
	const text = await response.text();
	const trimmed = text.trim();

	if ( trimmed.startsWith( '<!DOCTYPE' ) || trimmed.startsWith( '<html' ) ) {
		document.open();
		document.write( text );
		document.close();
		return null;
	}

	return JSON.parse( text ) as BookerResponse;
}

export async function loadCurrentBooker(): Promise< Booker > {
	const data = new FormData();
	data.append( 'action', 'rrze_appointment_get_booker' );
	const response = await fetch(
		window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php',
		{ method: 'POST', body: data }
	);
	const result = ( await response.json() ) as BookerAjaxResponse;
	return result.success ? result.data || {} : {};
}
