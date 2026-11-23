import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { setImmediate as nextTurn } from 'node:timers/promises';
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
 * @param overrides           Block attributes to override for this scenario.
 * @param directory           Source or built template directory.
 * @param context             Page status and query parameters for navigation.
 * @param context.privatePage Whether the viewer is reading a private page.
 * @param context.query       Query parameters on the containing page.
 */
function renderMarkup(
	overrides: Record< string, unknown > = {},
	directory = 'src',
	context: { privatePage?: boolean; query?: Record< string, string > } = {}
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
			$_GET = json_decode(base64_decode('${ Buffer.from(
				JSON.stringify( context.query || {} )
			).toString( 'base64' ) }'), true);
			/** Minimal post for the renderer's visitor visibility check. */
			class WP_Post { public $ID = 7; public $post_type = 'page'; public $post_status = '${
				context.privatePage ? 'private' : 'publish'
			}'; }
			/** Supplies the post owning the rendered block. */
			function get_post($id) { return new WP_Post(); }
			/** Models the published page or authorized private reader. */
			function is_post_publicly_viewable($post) { return $post->post_status === 'publish'; }
			/** The test visitor has passed the page password prompt. */
			function post_password_required($post) { return false; }
			/** Allows frontend rendering for pages. */
			function is_post_type_viewable($type) { return $type === 'page'; }
			/** Models an authenticated reader. */
			function is_user_logged_in() { return true; }
			/** Grants read access to this specific private page. */
			function current_user_can($cap, $id) { return $cap === 'read_post' && $id === 7; }
			/** Each fixture process renders one occurrence of the block. */
			function wp_unique_prefixed_id($prefix) { return $prefix . '1'; }
			/** Updates only the selected block's month. */
			function add_query_arg($key, $value) { return '?' . http_build_query(array_merge($_GET, [$key => $value])); }
			/** Formats the date returned by the fixed-clock fixture. */
			function mysql2date($format, $date) { return (new DateTimeImmutable($date))->format($format); }
			/** Escapes a page navigation URL. */
			function esc_url($url) { return esc_attr($url); }
			/** Prints an escaped navigation label. */
			function esc_attr_e($text, $domain) { echo esc_attr($text); }
			function current_time($type) { return ${ Math.floor( Date.now() / 1000 ) }; }
			function current_datetime() { return (new DateTimeImmutable('@' . current_time('timestamp')))->setTimezone(wp_timezone()); }
			function is_preview() { return false; }
			/** Supplies the date format and booked times used by this fixture. */
			function get_option($key, $default = []) {
				if ($key === 'date_format') { return 'Y-m-d'; }
				return $key === 'rrze_appointment_booked_slots' ? json_decode(base64_decode('${ Buffer.from(
					JSON.stringify( window.rrze_appointment?.bookedSlots || [] )
				).toString( 'base64' ) }'), true) : $default;
			}
			function wp_timezone() { return new DateTimeZone(${ JSON.stringify(
				Intl.DateTimeFormat().resolvedOptions().timeZone
			) }); }
			function get_the_ID() { return 7; }
			function wp_salt($scheme) { return 'test-salt'; }
			function wp_json_encode($value) { return json_encode($value); }
			function __($text, $domain) { return $text; }
			function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
			function esc_html($text) { return esc_attr($text); }
			function esc_attr__($text, $domain) { return esc_attr($text); }
			function esc_html__($text, $domain) { return esc_html($text); }
			function esc_html_e($text, $domain) { echo esc_html($text); }
			require ${ projectPath } . '/tests/fixtures/legacy-booking-storage.php';
			require ${ projectPath } . '/includes/Booking/Bookings.php';
			require ${ projectPath } . '/includes/Booking/TokenManager.php';
			require ${ projectPath } . '/includes/Booking/BookingWindow.php';
			require ${ projectPath } . '/includes/Booking/MonthlyAvailability.php';
			require ${ projectPath } . '/includes/Booking/SlotGenerator.php';
			require ${ projectPath } . '/includes/Booking/AppointmentBlock.php';
			$attributes = json_decode(base64_decode('${ attributes }'), true);
			require ${ JSON.stringify( resolve( directory, 'render.php' ) ) };
			`,
		],
		{ encoding: 'utf8' }
	);
}

/**
 * Renders a real block and attaches its frontend controls.
 *
 * @param overrides Attributes for this scenario.
 * @return Initialized form.
 */
function renderAppointment( overrides: Record< string, unknown > = {} ) {
	document.body.innerHTML = renderMarkup( overrides );
	initializeAppointmentForms();
	return document.querySelector< HTMLFormElement >(
		'form.rrze-appointment'
	)!;
}

/**
 * Returns currently visible chronological date sections.
 *
 * @param form Appointment form.
 * @return Rendered date sections.
 */
function dateGroups( form: Element ): HTMLElement[] {
	return Array.from(
		form.querySelectorAll< HTMLElement >(
			'.rrze-appointment__date-list section'
		)
	);
}

/**
 * Looks up a rendered appointment by its canonical value.
 *
 * @param value Appointment identifier.
 * @return Matching time button.
 */
function slotButton( value: string ): HTMLButtonElement {
	return document.querySelector< HTMLButtonElement >(
		`button[data-slot-value="${ value }"]`
	)!;
}

/** Drains the asynchronous fetch and navigation callbacks. */
async function flushRequests() {
	await nextTurn();
}

/** Submits the opened guest booking dialog. */
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

/** Dismisses a fixture dialog if one is present. */
function closeDialog() {
	document
		.querySelector< HTMLButtonElement >(
			'.rrze-appointment__overlay-close'
		)
		?.click();
}

/** Returns the server response for the fixture's later appointment. */
function februaryResponse() {
	return {
		ok: true,
		json: async () => ( {
			success: true,
			data: {
				month: '2099-02',
				slots: {
					[ laterSlot ]:
						new Date( '2099-02-02T14:00:00' ).getTime() / 1000,
				},
			},
		} ),
	};
}

/**
 * Requests the next month through its visible list control.
 *
 * @param form Published list.
 */
async function loadMore( form: Element ) {
	form.querySelector< HTMLButtonElement >(
		'.rrze-appointment__more'
	)!.click();
	await flushRequests();
}

describe( 'frontend list view', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2099-01-10T09:00:00' ) );
		window.rrze_appointment = { locale: 'en-GB', bookedSlots: [] };
		global.fetch = jest
			.fn()
			.mockImplementation( async ( _url, options ) =>
				options.body.get( 'action' ) === 'rrze_appointment_get_month'
					? februaryResponse()
					: { ok: true, json: async () => ( { success: true } ) }
			);
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

	it( 'renders 2.4.0 radio values and page links through an empty month', () => {
		const attrs = {
			availabilities: [
				...defaultAvailabilities,
				{ ...defaultAvailabilities[ 0 ], date: '2099-03-02' },
			],
			dateOverrides: { '2099-02-02': { removedSlots: [ laterSlot ] } },
		};
		document.body.innerHTML = renderMarkup( attrs );
		const id = document.querySelector( 'form' )!.id;
		/** Reads the canonical input values understood by the 2.4.0 frontend. */
		const radios = () =>
			Array.from(
				document.querySelectorAll< HTMLInputElement >(
					'input[type="radio"]'
				),
				( input ) => input.value
			);
		expect( radios() ).toEqual( [ firstSlot, secondSlot ] );
		const link = document.querySelector< HTMLAnchorElement >( 'nav a' )!;
		expect( new URL( link.href ).searchParams.get( id ) ).toBe( '2099-02' );
		expect( link.hash ).toBe( '#' + id );
		initializeAppointmentForms();
		expect( radios() ).toEqual( [] );
		expect( link.parentElement!.hidden ).toBe( true );

		document.body.innerHTML = renderMarkup( attrs, 'src', {
			query: { [ id ]: '2099-02' },
		} );
		expect( radios() ).toEqual( [] );
		expect(
			document.querySelector( '.rrze-appointment__fallback-times' )
				?.textContent
		).toContain( 'No time slots available' );
		initializeAppointmentForms();
		expect( document.querySelector< HTMLElement >( 'nav' )!.hidden ).toBe(
			false
		);
		expect(
			Array.from(
				document.querySelectorAll< HTMLAnchorElement >( 'nav a' ),
				( a ) => new URL( a.href ).searchParams.get( id )
			)
		).toEqual( [ '2099-01', '2099-03' ] );
		document.body.innerHTML = renderMarkup( attrs, 'src', {
			query: { [ id ]: '2099-03' },
		} );
		expect( radios() ).toEqual( [ '2099-03-02 14:00-14:30' ] );
		document.body.innerHTML = renderMarkup( attrs, 'src', {
			query: { [ id ]: '2099-04' },
		} );
		expect( radios() ).toEqual( [ firstSlot, secondSlot ] );
	} );

	it( 'offers a page link back to a fully booked month before the first available month', () => {
		window.rrze_appointment!.bookedSlots = [ firstSlot, secondSlot ];
		document.body.innerHTML = renderMarkup();
		const form = document.querySelector( 'form' )!;
		const previous = form.querySelector< HTMLAnchorElement >( 'nav a' )!;
		expect( new URL( previous.href ).searchParams.get( form.id ) ).toBe(
			'2099-01'
		);
		expect(
			form.querySelector< HTMLInputElement >( 'input[type="radio"]' )!
				.value
		).toBe( laterSlot );
	} );

	it( 'presents private times as text before enhancement and after loading another month', async () => {
		document.body.innerHTML = renderMarkup( {}, 'src', {
			privatePage: true,
		} );
		expect(
			document.querySelector( '.rrze-appointment__fallback-times' )
				?.textContent
		).toContain( '10:00 – 10:30' );
		expect(
			document.querySelector( 'input[name="rrze_appointment_slot"]' )
		).toBeNull();
		initializeAppointmentForms();
		const form = document.querySelector( 'form' )!;
		expect(
			form.querySelector( '.rrze-appointment__fallback-times' )
		).toBeNull();
		await loadMore( form );
		expect( dateGroups( form )[ 1 ].textContent ).toContain(
			'14:00 - 14:30'
		);
		expect( form.querySelector( 'button[data-slot-value]' ) ).toBeNull();
	} );

	it( 'appends a requested month under localized, chronological headings', async () => {
		window.rrze_appointment = {
			locale: 'de-DE',
			i18n: { availableOn: 'Verfügbare Termine am %s' },
		};
		const form = renderAppointment();
		expect(
			dateGroups( form ).map( ( group ) => group.dataset.date )
		).toEqual( [ '2099-01-20' ] );
		expect( form.textContent ).not.toContain( laterSlot );
		await loadMore( form );
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

	it( 'keeps loaded dates on failure, retries and skips an empty intermediate month', async () => {
		const marchSlot = '2099-03-02 14:00-14:30';
		const form = renderAppointment( {
			availabilities: [
				...defaultAvailabilities,
				{ ...defaultAvailabilities[ 0 ], date: '2099-03-02' },
			],
		} );
		const fetch = global.fetch as jest.Mock;
		fetch
			.mockRejectedValueOnce( new Error( 'offline' ) )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( {
					success: true,
					data: { month: '2099-02', slots: {} },
				} ),
			} )
			.mockResolvedValueOnce( {
				ok: true,
				json: async () => ( {
					success: true,
					data: {
						month: '2099-03',
						slots: {
							[ marchSlot ]:
								Date.parse( '2099-03-02T14:00:00Z' ) / 1000,
						},
					},
				} ),
			} );
		await loadMore( form );
		expect(
			dateGroups( form ).map( ( group ) => group.dataset.date )
		).toEqual( [ '2099-01-20' ] );
		expect( slotButton( firstSlot ).disabled ).toBe( false );
		const retry = form.querySelector< HTMLButtonElement >(
			'.rrze-appointment__retry'
		)!;
		expect( retry.hidden ).toBe( false );
		retry.click();
		await flushRequests();
		expect(
			dateGroups( form ).map( ( group ) => group.dataset.date )
		).toEqual( [ '2099-01-20', '2099-03-02' ] );
		expect(
			form.querySelector< HTMLButtonElement >(
				'.rrze-appointment__more'
			)!.hidden
		).toBe( true );
		expect( retry.hidden ).toBe( true );
	} );

	it( 'opens the next visible month when a cached first month has expired', async () => {
		document.body.innerHTML = renderMarkup();
		jest.setSystemTime( new Date( '2099-02-01T09:00:00' ) );
		initializeAppointmentForms();
		await flushRequests();
		const form = document.querySelector( 'form.rrze-appointment' )!;
		expect(
			dateGroups( form ).map( ( group ) => group.dataset.date )
		).toEqual( [ '2099-02-02' ] );
	} );

	it( 'ignores an older month response after quickly navigating back', async () => {
		const form = renderAppointment( { showSlotsAsList: false } );
		let finish!: (
			response: ReturnType< typeof februaryResponse >
		) => void;
		( global.fetch as jest.Mock ).mockReturnValueOnce(
			new Promise( ( deliver ) => {
				finish = deliver;
			} )
		);
		form.querySelector< HTMLButtonElement >(
			'[data-direction="next"]'
		)!.click();
		form.querySelector< HTMLButtonElement >(
			'[data-direction="previous"]'
		)!.click();
		finish( februaryResponse() );
		await flushRequests();
		expect(
			form.querySelector( '.rrze-appointment__calendar-month-title' )
				?.textContent
		).toContain( 'January' );
		expect( form.hasAttribute( 'aria-busy' ) ).toBe( false );
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
		await loadMore( form );
		slotButton( laterSlot ).click();
		await submitDialog();
		const payload = ( global.fetch as jest.Mock ).mock.calls.at( -1 )[ 1 ]
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
			form.querySelector( '.rrze-appointment__missing-slot' )?.textContent
		).toBe( 'No time slots available.' );
		const emptyForm = renderAppointment( { availabilities: [] } );
		expect(
			emptyForm.querySelector( '.rrze-appointment__missing-slot' )
				?.textContent
		).toBe( 'No time slots available.' );
	} );

	it( 'retains opening notifications and their per-date booking window notice', async () => {
		const form = renderAppointment( { bookingMaxAdvance: 14 * 24 * 60 } );
		await loadMore( form );
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
		const payload = ( global.fetch as jest.Mock ).mock.calls.at( -1 )[ 1 ]
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
		global.fetch = jest
			.fn()
			.mockResolvedValueOnce( februaryResponse() )
			.mockResolvedValue( {
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
			form.querySelector< HTMLInputElement >(
				'input[name=rrze_appointment_slot]'
			)?.value
		).toBe( laterSlot );
		expect( dateGroups( form ) ).toHaveLength( 2 );
		closeDialog();
		expect( form.ownerDocument.activeElement ).toBe(
			slotButton( laterSlot )
		);
	} );

	it( 'supports multiple list blocks alongside the default calendar with unique heading IDs', async () => {
		document.body.innerHTML =
			renderMarkup() +
			renderMarkup() +
			renderMarkup( { showSlotsAsList: false } );
		initializeAppointmentForms();
		const forms = document.querySelectorAll( 'form.rrze-appointment' );
		await loadMore( forms[ 0 ] );
		expect( dateGroups( forms[ 1 ] ) ).toHaveLength( 1 );
		await loadMore( forms[ 1 ] );
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
