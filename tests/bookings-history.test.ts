import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type Booking = {
	slot: string;
	title: string;
	person_name: string;
	booker_name: string;
	booker_email: string;
	admin_anonymized: boolean;
};

function runScenario( scenario: Record< string, unknown > = {} ): {
	html: string;
	bookings: Booking[];
	storedSlots: string[];
	writes: number;
	api: {
		status?: number;
		headers?: Record< string, string >;
		code?: string;
		data: any;
	};
	mails: Array< { to: string; plain: string } >;
	clearedHooks: unknown[];
	routes: Array< {
		route: string;
		methods: string;
		permission: string;
		args: Record< string, unknown >;
	} >;
} {
	return JSON.parse(
		execFileSync(
			'php',
			[ resolve( 'tests/fixtures/bookings-overview.php' ) ],
			{ input: JSON.stringify( scenario ), encoding: 'utf8' }
		)
	);
}

describe( 'retained booking queries', () => {
	it( 'keeps ongoing and upcoming bookings in the current view in the site timezone', () => {
		const { bookings } = runScenario();
		expect( bookings.map( ( booking ) => booking.title ) ).toEqual( [
			'Ongoing appointment',
			'Upcoming appointment',
			'Future appointment',
		] );
	} );

	it( 'shows completed bookings newest first only within the retention period, without deleting data', () => {
		const result = runScenario( { filter: { view: 'past' } } );
		expect( result.bookings.map( ( booking ) => booking.slot ) ).toEqual( [
			'2026-08-20 11:30-12:00',
			'2026-08-19 10:00-10:30',
			'2026-08-19 09:00-09:30',
			'2026-07-21 11:31-12:01',
		] );
		expect( result.storedSlots ).toContain( '2026-07-21 11:30-12:00' );
		expect( result.writes ).toBe( 0 );
	} );

	it.each( [ 0, -1 ] )(
		'does not expose past bookings with a retention of %i days',
		( retention ) => {
			expect(
				runScenario( { retention, filter: { view: 'past' } } ).bookings
			).toEqual( [] );
		}
	);

	it( 'applies changed retention settings and the exact appointment end boundary', () => {
		expect(
			runScenario( {
				retention: 1,
				filter: { view: 'past' },
			} ).bookings.map( ( booking ) => booking.title )
		).toEqual( [ 'Just ended appointment' ] );
		const beforeEnd = runScenario( {
			now: '2026-08-20 11:59:59',
			filter: { view: 'past' },
		} );
		expect(
			beforeEnd.bookings.some(
				( booking ) => booking.title === 'Just ended appointment'
			)
		).toBe( false );
	} );

	it( 'supports inclusive date and host filters in the past view', () => {
		const result = runScenario( {
			filter: {
				view: 'past',
				date_from: '2026-08-19',
				date_to: '2026-08-19',
				person_id: 1,
			},
		} );
		expect( result.bookings ).toHaveLength( 1 );
		expect( result.bookings[ 0 ] ).toMatchObject( {
			title: 'Recent appointment',
			booker_name: 'Alice Example',
			booker_email: 'alice@example.test',
		} );
	} );
} );

describe( 'authenticated booking administration API', () => {
	it.each( [ { canManage: true }, { canManage: false, userId: 7 } ] )(
		'allows administrators and explicitly listed site members: %j',
		( access ) => {
			const result = runScenario( access );
			expect( result.api.status ).toBe( 200 );
			expect( result.html ).toContain( 'id="rrze-appointment-admin"' );
			expect( result.html ).not.toContain( 'upcoming@example.test' );
		}
	);

	it.each( [
		{ canManage: false, userId: 0 },
		{ canManage: false, userId: 8 },
		{ canManage: false, userId: 7, allowedUsers: [] },
		{ canManage: false, userId: 7, member: false },
	] )(
		'denies the page and both API operations without permission: %j',
		( access ) => {
			for ( const cancel of [ false, true ] ) {
				const result = runScenario( {
					...access,
					cancel,
					params: { slot: '2026-08-20 12:30-13:00' },
				} );
				expect( result.api.code ).toBe( 'rrze_appointment_forbidden' );
				expect( result.api.data.status ).toBe( 403 );
				expect( result.html ).toBe( '' );
				expect( result.writes ).toBe( 0 );
				expect( result.mails ).toHaveLength( 0 );
			}
		}
	);

	it.each( [ '', 'invalid' ] )(
		'requires a valid nonce for reading and cancelling: %s',
		( nonce ) => {
			for ( const cancel of [ false, true ] ) {
				const result = runScenario( {
					nonce,
					cancel,
					params: { slot: '2026-08-20 12:30-13:00' },
				} );
				expect( result.api.code ).toBe(
					'rrze_appointment_invalid_nonce'
				);
				expect( result.writes ).toBe( 0 );
				expect( result.mails ).toHaveLength( 0 );
			}
		}
	);

	it( 'registers protected routes and validates cancellation inputs', () => {
		const { routes } = runScenario();
		expect(
			routes.map( ( route ) => [
				route.route,
				route.methods,
				route.permission,
			] )
		).toEqual( [
			[ '/bookings', 'GET', 'allowRequest' ],
			[ '/bookings/cancel', 'POST', 'allowRequest' ],
		] );
		expect( routes[ 1 ].args ).toMatchObject( {
			slot: { type: 'string', required: true },
			reason: {
				type: 'string',
				maxLength: 2000,
				sanitize_callback: 'sanitize_textarea_field',
			},
		} );
	} );

	it( 'returns only retained bookings for the past view and prevents response caching', () => {
		const { api } = runScenario( { params: { view: 'past' } } );
		expect( api.headers?.[ 'Cache-Control' ] ).toBe( 'private, no-store' );
		expect( api.data ).toMatchObject( { view: 'past', hasPastView: true } );
		expect( api.data.items ).toHaveLength( 4 );
		expect(
			api.data.items.map( ( item: { title: string } ) => item.title )
		).not.toContain( 'Expired appointment' );
	} );

	it( 'falls back to current appointments if the retention period is zero', () => {
		const { api } = runScenario( {
			retention: 0,
			params: { view: 'past' },
		} );
		expect( api.data ).toMatchObject( {
			view: 'current',
			hasPastView: false,
		} );
		expect( api.data.items ).toHaveLength( 3 );
	} );

	it( 'keeps the appointment title visible while excluding sensitive personal data and internal metadata', () => {
		for ( const sensitive of [ false, true ] ) {
			const { api } = runScenario( {
				sensitive,
				params: { view: 'past' },
			} );
			const hidden = api.data.items.find(
				( item: { id: string } ) => item.id === '2026-08-19 10:00-10:30'
			);
			expect( hidden ).toMatchObject( {
				title: 'Private topic',
				anonymized: true,
				personName: '',
				bookerName: '',
				bookerEmail: '',
			} );
			expect( Object.keys( hidden ).sort() ).toEqual(
				[
					'id',
					'date',
					'dateLabel',
					'time',
					'title',
					'personName',
					'bookerName',
					'bookerEmail',
					'anonymized',
				].sort()
			);
			expect( JSON.stringify( api ) ).not.toContain(
				'private@example.test'
			);
			if ( sensitive ) {
				expect( JSON.stringify( api ) ).not.toContain(
					'alice@example.test'
				);
				expect( JSON.stringify( api ) ).toContain(
					'Recent appointment'
				);
			}
		}
	} );

	it.each( [
		'2026-08-19 09:00-09:30',
		'2026-08-20 11:30-12:00',
		'2099-01-01 10:00-10:30',
	] )(
		'rejects ended or nonexistent bookings without side effects: %s',
		( slot ) => {
			const result = runScenario( { cancel: true, params: { slot } } );
			expect( result.api.data.status ).toBe( 409 );
			expect( result.writes ).toBe( 0 );
			expect( result.mails ).toHaveLength( 0 );
			expect( result.clearedHooks ).toHaveLength( 0 );
		}
	);

	it.each( [ true, false ] )(
		'cancels through the existing workflow and honors the reason setting: %s',
		( showReason ) => {
			const result = runScenario( {
				showReason,
				cancel: true,
				params: {
					slot: '2026-08-20 12:30-13:00',
					reason: '<b>Room closed</b>',
				},
			} );
			expect( result.api.status ).toBe( 200 );
			expect( result.storedSlots ).not.toContain(
				'2026-08-20 12:30-13:00'
			);
			expect( result.mails.map( ( mail ) => mail.to ) ).toEqual( [
				'host@example.test',
				'upcoming@example.test',
			] );
			result.mails.forEach( ( mail ) => {
				expect( mail.plain.includes( 'Room closed' ) ).toBe(
					showReason
				);
				expect( mail.plain ).not.toContain( '<b>' );
			} );
		}
	);
} );
