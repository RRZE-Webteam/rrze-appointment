import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

const fingerprint = ( attributes: Record< string, unknown > ): string => {
	const appointmentBlockPath = resolve(
		process.cwd(),
		'includes/AppointmentBlock.php'
	);
	const encodedAttributes = JSON.stringify( JSON.stringify( attributes ) );
	const php = `
		define( 'ABSPATH', __DIR__ );
		function wp_json_encode( $value ) {
			return json_encode( $value );
		}
		function wp_salt( $scheme ) {
			return 'test-' . $scheme . '-salt';
		}
		require ${ JSON.stringify( appointmentBlockPath ) };
		$attributes = json_decode( ${ encodedAttributes }, true );
		echo \\RRZE\\Appointment\\AppointmentBlock::fingerprint( $attributes );
	`;

	return execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } );
};

describe( 'appointment block fingerprint', () => {
	it( 'is independent of associative attribute order', () => {
		expect(
			fingerprint( {
				location: 'Room 1',
				schedule: { end: '11:00', start: '10:00' },
			} )
		).toBe(
			fingerprint( {
				schedule: { start: '10:00', end: '11:00' },
				location: 'Room 1',
			} )
		);
	} );

	it( 'preserves meaningful list order', () => {
		expect( fingerprint( { slots: [ '10:00', '11:00' ] } ) ).not.toBe(
			fingerprint( { slots: [ '11:00', '10:00' ] } )
		);
	} );
} );
