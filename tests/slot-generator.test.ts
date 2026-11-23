import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { generateTimeSlots } from '../src/scheduling/schedule';
import type { AppointmentAttributes } from '../src/scheduling/types';

/**
 * Runs the public PHP slot generator with a fixed clock and a UTC site.
 *
 * @param attributes Saved appointment block attributes.
 * @param from       Inclusive first occurrence date.
 * @param until      Inclusive last occurrence date.
 * @return Sorted slot identifiers produced by the plugin.
 */
function generateSlotsWithPhp(
	attributes: Record< string, unknown >,
	from = '',
	until = '9999-12-31'
): string[] {
	const slotGeneratorPath = resolve(
		process.cwd(),
		'includes/Booking/SlotGenerator.php'
	);
	const php = `
		define( 'ABSPATH', __DIR__ );
		function current_time( $type ) { return strtotime('2026-11-23T00:00:00Z'); }
		function wp_timezone() { return new DateTimeZone( 'UTC' ); }
		require ${ JSON.stringify( slotGeneratorPath ) };
		$attributes = json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR );
		echo json_encode( \\RRZE\\Appointment\\Booking\\SlotGenerator::fromAttributes( $attributes, $argv[2], $argv[3] ) );
	`;

	return JSON.parse(
		execFileSync(
			'php',
			[ '-r', php, JSON.stringify( attributes ), from, until ],
			{
				encoding: 'utf8',
			}
		)
	) as string[];
}

describe( 'PHP slot generator', () => {
	const availability = {
		date: '2099-01-01',
		startTime: '09:00',
		endTime: '10:00',
		duration: 30,
	};
	const legacy = {
		selectedDates: [ '2099-01-01' ],
		startTime: '15:00',
		endTime: '16:00',
		duration: 60,
	};

	it.each( [ 17, '17' ] )(
		'accepts a whole-minute duration of %p',
		( duration ) => {
			expect(
				generateSlotsWithPhp( {
					availabilities: [ { ...availability, duration } ],
				} )
			).toEqual( [
				'2099-01-01 09:00-09:17',
				'2099-01-01 09:17-09:34',
				'2099-01-01 09:34-09:51',
			] );
		}
	);

	it.each( [ 17.5, '17.5' ] )(
		'rejects a fractional duration of %p',
		( duration ) => {
			expect(
				generateSlotsWithPhp( {
					availabilities: [ { ...availability, duration } ],
				} )
			).toEqual( [] );
		}
	);

	it( 'applies recurrence exclusions and per-date slot edits to current entries', () => {
		expect(
			generateSlotsWithPhp( {
				...legacy,
				availabilities: [
					{
						...availability,
						recurrence: {
							freq: 'weekly',
							dates: [ '2099-01-01', '2099-01-08', '2099-01-15' ],
							excludedDates: [ '2099-01-08' ],
						},
					},
				],
				dateOverrides: {
					'2099-01-15': {
						removedSlots: [ '2099-01-15 09:00-09:30' ],
						extraSlots: [ '11:00|11:20' ],
					},
				},
			} )
		).toEqual( [
			'2099-01-01 09:00-09:30',
			'2099-01-01 09:30-10:00',
			'2099-01-15 09:30-10:00',
			'2099-01-15 11:00-11:20',
		] );
	} );

	it( 'honors an empty current availability list even with legacy dates', () => {
		expect(
			generateSlotsWithPhp( { ...legacy, availabilities: [] } )
		).toEqual( [] );
	} );

	it.each( [
		{ key: 'absent', attributes: {} },
		{ key: 'a scalar', attributes: { availabilities: 'invalid' } },
	] )(
		'uses legacy date and series overrides when availabilities is $key',
		( { attributes } ) => {
			expect(
				generateSlotsWithPhp( {
					...legacy,
					...attributes,
					selectedDates: [ '2099-01-01', '2099-01-08' ],
					recurrences: {
						'2099-01-01': { dates: [ '2099-01-01', '2099-01-08' ] },
					},
					dateOverrides: {
						'2099-01-01': { startTime: '14:00', duration: 30 },
						'2099-01-08': { startTime: '15:30' },
					},
				} )
			).toEqual( [
				'2099-01-01 14:00-14:30',
				'2099-01-01 14:30-15:00',
				'2099-01-01 15:00-15:30',
				'2099-01-01 15:30-16:00',
				'2099-01-08 15:30-16:00',
			] );
		}
	);
} );

/**
 * Materializes the weekdays of the reported series using UTC calendar dates.
 *
 * @param from  First ISO date.
 * @param until Last ISO date.
 * @param days  Included weekday numbers.
 * @return Saved occurrence dates.
 */
function weekdays( from: string, until: string, days: number[] ): string[] {
	const dates: string[] = [];
	for (
		const date = new Date( from );
		date.toISOString().slice( 0, 10 ) <= until;
		date.setUTCDate( date.getUTCDate() + 1 )
	) {
		if ( days.includes( date.getUTCDay() ) ) {
			dates.push( date.toISOString().slice( 0, 10 ) );
		}
	}
	return dates;
}

/**
 * Builds a saved series containing only scheduling attributes.
 *
 * @param date      Series anchor.
 * @param until     Last ISO date.
 * @param days      Included weekdays.
 * @param startTime Opening time.
 * @param endTime   Closing time.
 * @return Anonymous schedule entry.
 */
function series(
	date: string,
	until: string,
	days: number[],
	startTime: string,
	endTime: string
) {
	return {
		date,
		startTime,
		endTime,
		duration: 15,
		breakDuration: 15,
		recurrence: {
			freq: 'weekly',
			dates: weekdays( date, until, days ),
		},
	};
}

describe( 'range generation in PHP and the editor', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2026-11-23T00:00:00Z' ) );
	} );
	afterEach( () => jest.useRealTimers() );

	it( 'includes every series across the customer schedule regardless of order', () => {
		const dates = [ 1, 2, 3, 4, 5 ];
		const entries = [
			series( '2026-09-29', '2026-12-21', dates, '08:15', '11:45' ),
			series( '2026-09-29', '2026-12-21', [ 1, 2, 3 ], '13:15', '15:45' ),
			series( '2027-01-07', '2027-01-08', [ 4, 5 ], '08:30', '12:00' ),
			series( '2027-01-07', '2027-01-07', [ 4 ], '13:00', '15:45' ),
			series( '2027-01-11', '2027-06-30', dates, '08:15', '11:45' ),
			series( '2027-01-11', '2027-06-30', [ 1, 2, 3 ], '13:15', '15:45' ),
		];
		const dateOverrides = {
			'2027-01-07': {
				removedSlots: [
					'2027-01-07 09:00-09:15',
					'2027-01-07 11:00-11:15',
					'2027-01-07 14:00-14:15',
				],
			},
			'2027-01-08': { removedSlots: [ '2027-01-08 09:30-09:45' ] },
		};
		for ( const [ month, count ] of [
			[ '2027-01', 166 ],
			[ '2027-06', 224 ],
		] as const ) {
			const from = month + '-01';
			const until = month === '2027-01' ? month + '-31' : month + '-30';
			const expected = generateSlotsWithPhp(
				{ availabilities: entries, dateOverrides },
				from,
				until
			);
			expect( expected ).toHaveLength( count );
			for ( const availabilities of [
				entries,
				[ ...entries ].reverse(),
			] ) {
				const attrs = { availabilities, dateOverrides };
				expect( generateSlotsWithPhp( attrs, from, until ) ).toEqual(
					expected
				);
				expect(
					generateTimeSlots(
						attrs as unknown as AppointmentAttributes,
						{ from, until }
					).map( ( slot ) => slot.value )
				).toEqual( expected );
			}
			if ( month === '2027-01' ) {
				for ( const day of [ '11', '18', '25' ] ) {
					const daySlots = expected.filter( ( slot ) =>
						slot.startsWith( month + '-' + day )
					);
					expect( daySlots ).toHaveLength( 12 );
					expect(
						daySlots
							.slice( 7 )
							.map( ( slot ) => slot.slice( 11, 16 ) )
					).toEqual( [
						'13:15',
						'13:45',
						'14:15',
						'14:45',
						'15:15',
					] );
				}
			} else {
				expect( expected ).toContain( '2027-06-30 15:15-15:30' );
			}
		}
	} );

	it( 'uses a consistent duration for legacy manual times when series are reordered', () => {
		const day = '2027-01-11';
		const morning = series( day, day, [ 1 ], '09:00', '09:15' );
		const afternoon = {
			...series( day, day, [ 1 ], '13:00', '13:30' ),
			duration: 30,
		};
		for ( const availabilities of [
			[ morning, afternoon ],
			[ afternoon, morning ],
		] ) {
			const attrs = {
				availabilities,
				dateOverrides: { [ day ]: { extraSlots: [ '16:00' ] } },
			};
			const slots = generateSlotsWithPhp( attrs, day, day );
			expect( slots ).toContain( '2027-01-11 16:00-16:15' );
			expect(
				generateTimeSlots(
					attrs as unknown as AppointmentAttributes
				).map( ( s ) => s.value )
			).toEqual( slots );
		}
	} );

	it( 'returns over 1,000 unique slots even within a single month', () => {
		const entry = {
			...series( '2027-01-04', '2027-01-05', [ 1, 2 ], '08:00', '18:00' ),
			duration: 1,
			breakDuration: 0,
		};
		const attrs = { availabilities: [ entry, entry ] };
		const slots = generateSlotsWithPhp( attrs );
		expect( slots ).toHaveLength( 1200 );
		expect(
			generateTimeSlots( attrs as unknown as AppointmentAttributes ).map(
				( slot ) => slot.value
			)
		).toEqual( slots );
	} );
} );
