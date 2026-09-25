import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { initializeAppointmentForms } from '../src/frontend/appointment-form';

const firstSlot = '2099-01-20 10:00-10:30';
const secondSlot = '2099-01-20 10:30-11:00';
const laterSlot = '2099-02-02 14:00-14:30';
const defaultAvailabilities = [
	{ date: '2099-02-02', startTime: '14:00', endTime: '14:30' },
	{ date: '2099-01-20', startTime: '10:00', endTime: '11:00' },
].map( ( availability ) => ( {
	...availability,
	duration: 30,
	breakDuration: 0,
	recurrence: [],
} ) );

/**
 * Exercise the actual PHP template and slot generator, without a database.
 * @param overrides Block attributes to override for this scenario.
 * @param directory Source or built template directory.
 */
function renderMarkup(
	overrides: Record< string, unknown > = {},
	directory = 'src'
): string {
	const attributes = Buffer.from(
		JSON.stringify( {
			title: 'Office hours',
			disableSso: true,
			showSlotsAsList: true,
			availabilities: defaultAvailabilities,
			...overrides,
		} )
	).toString( 'base64' );
	const projectPath = JSON.stringify( process.cwd() );
	return execFileSync(
		'php',
		[
			'-r',
			`
			define('ABSPATH', __DIR__);
			function current_time($type) { return 0; }
			function wp_timezone() { return new DateTimeZone('UTC'); }
			function get_the_ID() { return 7; }
			function wp_salt($scheme) { return 'test-salt'; }
			function wp_json_encode($value) { return json_encode($value); }
			function __($text, $domain) { return $text; }
			function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
			function esc_html($text) { return esc_attr($text); }
			function esc_attr__($text, $domain) { return esc_attr($text); }
			function esc_html__($text, $domain) { return esc_html($text); }
			function esc_html_e($text, $domain) { echo esc_html($text); }
			require ${ projectPath } . '/includes/Booking/SlotGenerator.php';
			require ${ projectPath } . '/includes/Booking/AppointmentBlock.php';
			$attributes = json_decode(base64_decode('${ attributes }'), true);
			require ${ JSON.stringify( resolve( directory, 'render.php' ) ) };
			`,
		],
		{ encoding: 'utf8' }
	);
}

function renderAppointment( overrides: Record< string, unknown > = {} ) {
	document.body.innerHTML = renderMarkup( overrides );
	initializeAppointmentForms();
	return document.querySelector< HTMLFormElement >(
		'form.rrze-appointment'
	)!;
}

function dateGroups( form: Element ): HTMLElement[] {
	return Array.from(
		form.querySelectorAll< HTMLElement >(
			'.rrze-appointment__date-list section'
		)
	);
}

function slotButton( value: string ): HTMLButtonElement {
	return document.querySelector< HTMLButtonElement >(
		`button[data-slot-value="${ value }"]`
	)!;
}

async function flushRequests() {
	// Fetch and JSON mocks resolve across several promise callbacks.
	for ( let index = 0; index < 10; index++ ) {
		await Promise.resolve();
	}
}

async function submitDialog() {
	document.querySelector< HTMLInputElement >(
		'.rrze-appointment__overlay-name'
	)!.value = 'Ada Lovelace';
	document.querySelector< HTMLInputElement >(
		'.rrze-appointment__overlay-email'
	)!.value = 'ada@example.org';
	document
		.querySelector< HTMLFormElement >( '.rrze-appointment__overlay-form' )!
		.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
	await flushRequests();
}

function closeDialog() {
	document
		.querySelector< HTMLButtonElement >(
			'.rrze-appointment__overlay-close'
		)
		?.click();
}

describe( 'frontend list view', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2099-01-10T09:00:00' ) );
		window.rrze_appointment = { locale: 'en-GB', bookedSlots: [] };
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( { success: true } ),
		} );
	} );

	afterEach( () => {
		closeDialog();
		document.body.innerHTML = '';
		sessionStorage.clear();
		delete window.rrze_appointment;
		delete global.fetch;
		jest.useRealTimers();
	} );

	it.each( [ 'src', 'build' ] )(
		'%s renders the calendar by default and omits it in list mode',
		( directory ) => {
			const metadata = require( `../${ directory }/block.json` );
			expect( metadata.attributes.showSlotsAsList ).toEqual( {
				type: 'boolean',
				default: false,
			} );
			document.body.innerHTML = renderMarkup(
				{ showSlotsAsList: undefined },
				directory
			);
			expect(
				document.querySelector( '.rrze-appointment__calendar' )
			).not.toBeNull();
			expect(
				document.querySelector( '.rrze-appointment__calendar-legend' )
			).not.toBeNull();
			expect(
				document.querySelector( '.rrze-appointment__date-list' )
			).toBeNull();

			document.body.innerHTML = renderMarkup( {}, directory );
			expect(
				document.querySelector( '.rrze-appointment__calendar' )
			).toBeNull();
			expect(
				document.querySelector( '.rrze-appointment__calendar-legend' )
			).toBeNull();
			expect(
				document.querySelector( '.rrze-appointment__date-list' )
			).not.toBeNull();
		}
	);

	it( 'groups all dates chronologically under localized, accessible headings', () => {
		window.rrze_appointment = {
			locale: 'de-DE',
			i18n: { availableOn: 'Verfügbare Termine am %s' },
		};
		const form = renderAppointment();
		const groups = dateGroups( form );
		expect( groups.map( ( group ) => group.dataset.date ) ).toEqual( [
			'2099-01-20',
			'2099-02-02',
		] );
		expect(
			groups.map( ( group ) => group.querySelector( 'h3' )?.textContent )
		).toEqual( [
			'Verfügbare Termine am 20.01.2099',
			'Verfügbare Termine am 02.02.2099',
		] );
		groups.forEach( ( group ) => {
			expect( group.getAttribute( 'aria-labelledby' ) ).toBe(
				group.querySelector( 'h3' )!.id
			);
		} );
		expect(
			Array.from(
				form.querySelectorAll< HTMLButtonElement >(
					'.rrze-appointment__slot-button'
				)
			).map( ( button ) => button.dataset.slotValue )
		).toEqual( [ firstSlot, secondSlot, laterSlot ] );
		expect(
			slotButton( laterSlot ).getAttribute( 'aria-label' )
		).toContain( '2. Februar 2099' );
		slotButton( laterSlot ).click();
		closeDialog();
		expect( form.ownerDocument.activeElement ).toBe(
			slotButton( laterSlot )
		);
	} );

	it( 'omits booked, past, and cutoff slots and dates with no remaining slots', () => {
		window.rrze_appointment!.bookedSlots = [ firstSlot, laterSlot ];
		const form = renderAppointment( {
			bookingCutoff: 60,
			availabilities: [
				...defaultAvailabilities,
				{ ...defaultAvailabilities[ 0 ], date: '2099-01-09' },
				{
					...defaultAvailabilities[ 0 ],
					date: '2099-01-10',
					startTime: '09:30',
					endTime: '10:00',
				},
			],
		} );
		expect(
			dateGroups( form ).map( ( group ) => group.dataset.date )
		).toEqual( [ '2099-01-20' ] );
		expect(
			form.querySelectorAll( '.rrze-appointment__slot-button' )
		).toHaveLength( 1 );
		expect( slotButton( secondSlot ) ).not.toBeNull();
	} );

	it( 'books a slot on a later date, removes its empty group, and restores focus', async () => {
		const form = renderAppointment();
		slotButton( laterSlot ).click();
		await submitDialog();
		const payload = ( global.fetch as jest.Mock ).mock.calls[ 0 ][ 1 ]
			.body as FormData;
		expect( payload.get( 'action' ) ).toBe( 'rrze_appointment_book' );
		expect( payload.get( 'slot' ) ).toBe( laterSlot );
		expect( payload.get( 'post_id' ) ).toBe( '7' );
		expect( payload.get( 'block_id' ) ).toBe( form.dataset.blockId );
		expect( slotButton( laterSlot ) ).toBeNull();
		expect(
			dateGroups( form ).map( ( group ) => group.dataset.date )
		).toEqual( [ '2099-01-20' ] );
		closeDialog();
		expect( form.ownerDocument.activeElement ).toBe(
			form.querySelector( 'h3' )
		);
	} );

	it( 'announces an empty list and provides a focus target after the final booking', async () => {
		window.rrze_appointment!.bookedSlots = [ firstSlot, secondSlot ];
		const form = renderAppointment();
		slotButton( laterSlot ).click();
		await submitDialog();
		closeDialog();
		const status = form.querySelector(
			'.rrze-appointment__availability-status'
		)!;
		expect( dateGroups( form ) ).toHaveLength( 0 );
		expect( status.textContent ).toBe( 'No time slots available.' );
		expect( status.classList.contains( 'is-hidden' ) ).toBe( false );
		expect( form.ownerDocument.activeElement ).toBe( status );
	} );

	it( 'handles no available slots on initial load, including no generated slots', () => {
		window.rrze_appointment!.bookedSlots = [
			firstSlot,
			secondSlot,
			laterSlot,
		];
		const form = renderAppointment();
		expect( dateGroups( form ) ).toHaveLength( 0 );
		expect(
			form.querySelector(
				'.rrze-appointment__availability-status:not(.is-hidden)'
			)?.textContent
		).toBe( 'No time slots available.' );
		const emptyForm = renderAppointment( { availabilities: [] } );
		expect(
			emptyForm.querySelector( '.rrze-appointment__missing-slot' )
				?.textContent
		).toBe( 'No time slots available.' );
	} );

	it( 'retains opening notifications and their per-date booking window notice', async () => {
		const form = renderAppointment( { bookingMaxAdvance: 14 * 24 * 60 } );
		const groups = dateGroups( form );
		expect(
			groups[ 0 ].querySelector(
				'.rrze-appointment__booking-window-notice'
			)
		).toBeNull();
		expect( groups[ 1 ].querySelector( 'h3' )?.textContent ).toBe(
			'Appointments on 02/02/2099'
		);
		expect(
			groups[ 1 ].querySelector(
				'.rrze-appointment__booking-window-notice'
			)?.textContent
		).toContain( '14 days in advance' );
		expect(
			slotButton( laterSlot ).classList.contains( 'is-not-open' )
		).toBe( true );
		slotButton( laterSlot ).click();
		expect(
			document.querySelector( '.rrze-appointment__overlay-confirm' )
				?.textContent
		).toBe( 'Notify me' );
		await submitDialog();
		const payload = ( global.fetch as jest.Mock ).mock.calls[ 0 ][ 1 ]
			.body as FormData;
		expect( payload.get( 'action' ) ).toBe(
			'rrze_appointment_notify_opening'
		);
		expect( payload.get( 'slot' ) ).toBe( laterSlot );
		expect( dateGroups( form ) ).toHaveLength( 2 );
	} );

	it( 'restores a later-date booking after returning from SSO', async () => {
		sessionStorage.setItem( 'rrze_appt_slot', laterSlot );
		sessionStorage.setItem( 'rrze_appt_page', window.location.href );
		global.fetch = jest.fn().mockResolvedValue( {
			ok: true,
			json: async () => ( {
				success: true,
				data: { bookerName: 'Ada', bookerEmail: 'ada@example.org' },
			} ),
		} );
		const form = renderAppointment( { disableSso: false } );
		await flushRequests();
		expect(
			document.querySelector< HTMLInputElement >(
				'.rrze-appointment__overlay-name'
			)?.value
		).toBe( 'Ada' );
		expect(
			form.querySelector< HTMLInputElement >( 'input:checked' )?.value
		).toBe( laterSlot );
		expect( dateGroups( form ) ).toHaveLength( 2 );
		closeDialog();
		expect( form.ownerDocument.activeElement ).toBe(
			slotButton( laterSlot )
		);
	} );

	it( 'supports multiple list blocks alongside the default calendar with unique heading IDs', () => {
		document.body.innerHTML =
			renderMarkup() +
			renderMarkup() +
			renderMarkup( { showSlotsAsList: false } );
		initializeAppointmentForms();
		const forms = document.querySelectorAll( 'form.rrze-appointment' );
		expect( dateGroups( forms[ 0 ] ) ).toHaveLength( 2 );
		expect( dateGroups( forms[ 1 ] ) ).toHaveLength( 2 );
		expect(
			forms[ 2 ].querySelectorAll( '.rrze-appointment__calendar-month' )
		).toHaveLength( 1 );
		const ids = Array.from(
			document.querySelectorAll( '.rrze-appointment__date-list h3' )
		).map( ( heading ) => heading.id );
		expect( new Set( ids ).size ).toBe( 4 );
	} );
} );
