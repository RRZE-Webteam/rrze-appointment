import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

function generateSlotsWithPhp( duration: number | string ): string[] {
	const slotGeneratorPath = resolve(
		process.cwd(),
		'includes/SlotGenerator.php'
	);
	const php = `
		define( 'ABSPATH', __DIR__ );
		function current_time( $type ) { return 0; }
		function wp_timezone() { return new DateTimeZone( 'UTC' ); }
		require ${ JSON.stringify( slotGeneratorPath ) };
		$attributes = [
			'availabilities' => [ [
				'date' => '2099-01-01',
				'startTime' => '09:00',
				'endTime' => '10:00',
				'duration' => ${ JSON.stringify( duration ) },
				'breakDuration' => 0,
				'recurrence' => [],
			] ],
			'dateOverrides' => [],
		];
		echo json_encode( \\RRZE\\Appointment\\SlotGenerator::fromAttributes( $attributes ) );
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as string[];
}

describe( 'PHP slot generator duration validation', () => {
	it( 'accepts positive whole-minute durations', () => {
		expect( generateSlotsWithPhp( 17 ) ).toEqual( [
			'2099-01-01 09:00-09:17',
			'2099-01-01 09:17-09:34',
			'2099-01-01 09:34-09:51',
		] );
		expect( generateSlotsWithPhp( '17' ) ).toHaveLength( 3 );
	} );

	it( 'rejects fractional durations instead of truncating them', () => {
		expect( generateSlotsWithPhp( 17.5 ) ).toEqual( [] );
		expect( generateSlotsWithPhp( '17.5' ) ).toEqual( [] );
	} );
} );
