import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import {
	buildAvailabilityAttributes,
	getAvailabilityEntries,
} from '../src/scheduling/availability';
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
			until,
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

	it( 'preserves the 2.4.0 manual appointment IDs even after the editor sorts the series', () => {
		const day = '2027-01-11';
		const morning = series( day, day, [ 1 ], '09:00', '09:15' );
		const afternoon = {
			...series( day, day, [ 1 ], '13:00', '13:30' ),
			duration: 30,
		};
		for ( const [ availabilities, end, lateEnd ] of [
			[ [ morning, afternoon ], '16:15', '23:45' ],
			[ [ afternoon, morning ], '16:30', '24:00' ],
		] as const ) {
			const attrs = {
				availabilities: [
					{ ...morning, duration: 0 },
					...availabilities,
				],
				dateOverrides: {
					[ day ]: { extraSlots: [ '16:00', '23:30' ] },
				},
			} as unknown as AppointmentAttributes;
			const saved = buildAvailabilityAttributes(
				attrs,
				getAvailabilityEntries( attrs )
			);
			expect( saved.dateOverrides![ day ].extraSlots ).toEqual( [
				'16:00|' + end,
				'23:30|' + lateEnd,
			] );
			for ( const schedule of [ attrs, { ...attrs, ...saved } ] ) {
				const slots = generateSlotsWithPhp( schedule, day, day );
				expect( slots ).toEqual( [
					day + ' 09:00-09:15',
					day + ' 13:00-13:30',
					day + ' 16:00-' + end,
					day + ' 23:30-' + lateEnd,
				] );
				expect(
					generateTimeSlots( schedule ).map( ( s ) => s.value )
				).toEqual( slots );
			}
		}
	} );

	it( 'takes the duration from a published occurrence even if the editor projects another series onto that day', () => {
		const day = '2027-01-11';
		const original = {
			availabilities: [
				{
					date: '2027-01-04',
					startTime: '09:00',
					endTime: '09:15',
					duration: 15,
					breakDuration: 0,
					recurrence: { freq: 'weekly', until: day },
				},
				{
					date: day,
					startTime: '13:00',
					endTime: '13:30',
					duration: 30,
					breakDuration: 0,
				},
			],
			dateOverrides: { [ day ]: { extraSlots: [ '16:00' ] } },
		} as unknown as AppointmentAttributes;
		const edited = {
			...original,
			...buildAvailabilityAttributes(
				original,
				getAvailabilityEntries( original )
			),
		};
		for ( const attrs of [ original, edited ] ) {
			const expected = [ day + ' 16:00-16:30' ];
			expect(
				generateSlotsWithPhp( attrs ).filter( ( slot ) =>
					slot.includes( ' 16:00-' )
				)
			).toEqual( expected );
			expect(
				generateTimeSlots( attrs )
					.filter( ( slot ) => slot.isExtra )
					.map( ( slot ) => slot.value )
			).toEqual( expected );
		}
	} );

	it( 'keeps inherited legacy extras and exclusions when importing another window', () => {
		const first = '2027-01-11';
		const second = '2027-01-18';
		const hidden = second + ' 16:00-16:20';
		const attrs = {
			selectedDates: [ first, second ],
			startTime: '09:00',
			endTime: '10:00',
			duration: 30,
			breakDuration: 0,
			recurrence: {
				freq: 'weekly',
				anchor: first,
				until: second,
				dates: [ first, second ],
			},
			dateOverrides: {
				[ first ]: {
					extraSlots: [ '16:00' ],
					removedSlots: [ hidden ],
				},
				[ second ]: { duration: 20 },
			},
		} as unknown as AppointmentAttributes;
		const entries = getAvailabilityEntries( attrs );
		const imported = {
			...entries[ 0 ],
			id: 'imported',
			startTime: '08:00',
			endTime: '08:15',
			duration: 15,
		};
		const saved = {
			...attrs,
			...buildAvailabilityAttributes( attrs, [ imported, ...entries ] ),
		};
		expect( saved.dateOverrides[ second ].extraSlots ).toEqual( [
			'16:00|16:20',
		] );
		for ( const schedule of [ attrs, saved ] ) {
			expect(
				generateSlotsWithPhp( schedule ).filter( ( slot ) =>
					slot.includes( ' 16:00-' )
				)
			).toEqual( [ first + ' 16:00-16:30' ] );
			expect(
				generateTimeSlots( schedule, { includeExcluded: true } ).find(
					( slot ) => slot.value === hidden
				)?.isExcluded
			).toBe( true );
		}
	} );

	it( 'preserves overlapping legacy series, omitted days and inherited removals without manual slots', () => {
		const dates = [
			'2027-01-04',
			'2027-01-11',
			'2027-01-18',
			'2027-02-01',
		];
		const hidden = dates[ 2 ] + ' 09:00-09:20';
		const original = {
			selectedDates: dates,
			startTime: '08:00',
			endTime: '10:00',
			duration: 30,
			breakDuration: 0,
			recurrences: {
				[ dates[ 0 ] ]: {
					freq: 'weekly',
					until: dates[ 2 ],
					dates: dates.slice( 0, 3 ),
				},
				[ dates[ 1 ] ]: {
					freq: 'weekly',
					until: dates[ 3 ],
					dates: dates.slice( 1 ),
				},
			},
			dateOverrides: {
				[ dates[ 0 ] ]: {
					startTime: '09:00',
					removedSlots: [ hidden ],
				},
				[ dates[ 1 ] ]: { endTime: '11:00' },
				[ dates[ 2 ] ]: { duration: 20 },
			},
		} as unknown as AppointmentAttributes;
		const before = generateSlotsWithPhp( original );
		expect( before ).not.toContain( hidden );
		expect( before ).toContain( dates[ 2 ] + ' 09:20-09:40' );
		expect( before ).toContain( dates[ 3 ] + ' 08:00-08:30' );
		expect(
			before.some( ( slot ) => slot.startsWith( '2027-01-25' ) )
		).toBe( false );
		const saved = {
			...original,
			...buildAvailabilityAttributes(
				original,
				getAvailabilityEntries( original )
			),
		};
		expect( generateSlotsWithPhp( saved ) ).toEqual( before );
		expect(
			generateTimeSlots( saved ).map( ( slot ) => slot.value )
		).toEqual( before );
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
