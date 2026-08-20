import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { isBookingClosed, isBookingNotOpen } from '../src/booking-window';

const now = new Date( '2026-08-20T12:00:00Z' );
const minutesFromNow = ( minutes: number ) =>
	new Date( now.getTime() + minutes * 60 * 1000 );

function getPhpResults(): boolean[] {
	const bookingWindowPath = resolve(
		process.cwd(),
		'includes/BookingWindow.php'
	);
	const php = `
		define( 'ABSPATH', __DIR__ );
		require ${ JSON.stringify( bookingWindowPath ) };
		$now = new DateTimeImmutable( '2026-08-20T12:00:00Z' );
		$atCutoff = $now->modify( '+15 minutes' );
		$atOpening = $now->modify( '+14 days' );
		$beforeOpening = $atOpening->modify( '+1 minute' );
		echo json_encode( [
			\\RRZE\\Appointment\\BookingWindow::isClosed( $atCutoff, $now, 15 ),
			\\RRZE\\Appointment\\BookingWindow::isNotOpen( $atOpening, $now, 20160 ),
			\\RRZE\\Appointment\\BookingWindow::isNotOpen( $beforeOpening, $now, 20160 ),
			\\RRZE\\Appointment\\BookingWindow::isNotOpen( $beforeOpening, $now, 0 ),
			\\RRZE\\Appointment\\BookingWindow::isClosed( $now, $now, -15 ),
			\\RRZE\\Appointment\\BookingWindow::isNotOpen( $beforeOpening, $now, -15 ),
		] );
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as boolean[];
}

describe( 'booking window', () => {
	it( 'applies inclusive closing and opening boundaries', () => {
		expect( isBookingClosed( minutesFromNow( 15 ), now, 15 ) ).toBe( true );
		expect(
			isBookingNotOpen( minutesFromNow( 14 * 24 * 60 ), now, 20160 )
		).toBe( false );
		expect(
			isBookingNotOpen( minutesFromNow( 14 * 24 * 60 + 1 ), now, 20160 )
		).toBe( true );
	} );

	it( 'treats zero maximum advance as unrestricted', () => {
		expect(
			isBookingNotOpen( minutesFromNow( 365 * 24 * 60 ), now, 0 )
		).toBe( false );
	} );

	it( 'normalizes negative limits to zero', () => {
		expect( isBookingClosed( now, now, -15 ) ).toBe( true );
		expect( isBookingNotOpen( minutesFromNow( 60 ), now, -15 ) ).toBe(
			false
		);
	} );

	it( 'keeps PHP validation aligned with the browser rules', () => {
		expect( getPhpResults() ).toEqual( [
			true,
			false,
			true,
			false,
			true,
			false,
		] );
	} );
} );
