import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

/**
 * Runs the public PHP slot generator with a fixed clock and a UTC site.
 *
 * @param attributes Saved appointment block attributes.
 * @return Sorted slot identifiers produced by the plugin.
 */
function generateSlotsWithPhp(
	attributes: Record< string, unknown >
): string[] {
	const slotGeneratorPath = resolve(
		process.cwd(),
		'includes/Booking/SlotGenerator.php'
	);
	const php = `
		define( 'ABSPATH', __DIR__ );
		function current_time( $type ) { return 0; }
		function wp_timezone() { return new DateTimeZone( 'UTC' ); }
		require ${ JSON.stringify( slotGeneratorPath ) };
		$attributes = json_decode( $argv[1], true, 512, JSON_THROW_ON_ERROR );
		echo json_encode( \\RRZE\\Appointment\\Booking\\SlotGenerator::fromAttributes( $attributes ) );
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php, JSON.stringify( attributes ) ], {
			encoding: 'utf8',
		} )
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
