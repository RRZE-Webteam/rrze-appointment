import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

describe( 'plugin autoloader', () => {
	it( 'loads organized namespaces and preserves legacy class names', () => {
		const pluginPath = resolve( process.cwd(), 'rrze-appointment.php' );
		const php = `
			define( 'ABSPATH', __DIR__ );
			function register_deactivation_hook() {}
			function add_action() {}
			require ${ JSON.stringify( pluginPath ) };

			$pairs = [
				[ 'RRZE\\Appointment\\AppointmentBlock', 'RRZE\\Appointment\\Booking\\AppointmentBlock' ],
				[ 'RRZE\\Appointment\\AssetManager', 'RRZE\\Appointment\\Presentation\\AssetManager' ],
				[ 'RRZE\\Appointment\\BookingOpeningController', 'RRZE\\Appointment\\Controller\\BookingOpeningController' ],
				[ 'RRZE\\Appointment\\BookingRequestController', 'RRZE\\Appointment\\Controller\\BookingRequestController' ],
				[ 'RRZE\\Appointment\\BookingWindow', 'RRZE\\Appointment\\Booking\\BookingWindow' ],
				[ 'RRZE\\Appointment\\Bookings', 'RRZE\\Appointment\\Booking\\Bookings' ],
				[ 'RRZE\\Appointment\\CancellationController', 'RRZE\\Appointment\\Controller\\CancellationController' ],
				[ 'RRZE\\Appointment\\ConfirmationController', 'RRZE\\Appointment\\Controller\\ConfirmationController' ],
				[ 'RRZE\\Appointment\\MailTemplate', 'RRZE\\Appointment\\Mail\\MailTemplate' ],
				[ 'RRZE\\Appointment\\MailTemplatePost', 'RRZE\\Appointment\\Mail\\MailTemplatePost' ],
				[ 'RRZE\\Appointment\\PublicPageRenderer', 'RRZE\\Appointment\\Presentation\\PublicPageRenderer' ],
				[ 'RRZE\\Appointment\\SlotGenerator', 'RRZE\\Appointment\\Booking\\SlotGenerator' ],
				[ 'RRZE\\Appointment\\SsoController', 'RRZE\\Appointment\\Controller\\SsoController' ],
				[ 'RRZE\\Appointment\\TokenManager', 'RRZE\\Appointment\\Booking\\TokenManager' ],
				[ 'RRZE\\Appointment\\BookingOpeningNotifier', 'RRZE\\Appointment\\Notification\\BookingOpeningNotifier' ],
				[ 'RRZE\\Appointment\\Reminder', 'RRZE\\Appointment\\Notification\\Reminder' ],
				[ 'RRZE\\Appointment\\WaitlistNotifier', 'RRZE\\Appointment\\Notification\\WaitlistNotifier' ],
			];

			echo json_encode( array_map(
				fn( array $pair ): bool => class_exists( $pair[1] )
					&& class_exists( $pair[0] )
					&& is_a( $pair[0], $pair[1], true ),
				$pairs
			) );
		`;

		const result = JSON.parse(
			execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
		) as boolean[];

		expect( result ).toEqual( Array( 17 ).fill( true ) );
	} );
} );
