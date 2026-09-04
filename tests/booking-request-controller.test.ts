import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type RequestControllerResult = {
	request: Record< string, unknown >;
	meta: Record< string, unknown >;
	slotParts: string[];
	plain: string;
	html: string;
};

const getControllerResult = (): RequestControllerResult => {
	const controllerPath = resolve(
		process.cwd(),
		'includes/Controller/BookingRequestController.php'
	);
	const php = `
		namespace RRZE\\Appointment {
			class AppointmentException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Booking {
			class Bookings {
				public const SLOTS_OPTION = 'rrze_appointment_booked_slots';
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			function wp_unslash( $value ) {
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
			function sanitize_text_field( $value ) {
				return trim( strip_tags( (string) $value ) );
			}
			function sanitize_email( $value ) {
				return filter_var( $value, FILTER_SANITIZE_EMAIL );
			}
			function absint( $value ) {
				return abs( (int) $value );
			}
			function __( $text, $domain ) {
				return $text;
			}
			$_POST = [
				'slot' => ' 2099-01-01 10:00-10:30 ',
				'post_id' => '42',
				'block_id' => '<b>fingerprint</b>',
				'booker_email' => 'person@example.test',
				'booker_name' => "O\\\\'Connor",
				'booker_waitlist' => '1',
			];
			require ${ JSON.stringify( controllerPath ) };
			$controller = new \\RRZE\\Appointment\\Controller\\BookingRequestController();
			$reflection = new ReflectionClass( $controller );
			$getRequestData = $reflection->getMethod( 'getRequestData' );
			$buildMeta = $reflection->getMethod( 'buildPendingMeta' );
			$parseSlot = $reflection->getMethod( 'parseSlot' );
			$ensureLinks = $reflection->getMethod( 'ensureRequiredConfirmationLinks' );
			$request = $getRequestData->invoke( $controller );
			$context = [
				'title' => 'Consultation',
				'location' => 'Room 1',
				'person_id' => 7,
				'person_name' => 'Host',
				'person_email' => 'host@example.test',
				'tpl_id' => 9,
				'post_link' => 'https://example.test/appointment',
				'questions' => [ [ 'id' => 'topic' ] ],
			];
			$links = $ensureLinks->invoke( $controller, 'Custom body', '<p>Custom body</p>' );
			echo json_encode( [
				'request' => $request,
				'meta' => $buildMeta->invoke(
					$controller,
					$request,
					$context,
					'person@example.test',
					"O'Connor"
				),
				'slotParts' => $parseSlot->invoke( $controller, $request['slot'] ),
				'plain' => $links[0],
				'html' => $links[1],
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as RequestControllerResult;
};

describe( 'booking request controller', () => {
	it( 'normalizes requests and builds narrow pending metadata', () => {
		const result = getControllerResult();

		expect( result.request ).toEqual( {
			slot: '2099-01-01 10:00-10:30',
			postId: 42,
			blockFingerprint: 'fingerprint',
			email: 'person@example.test',
			name: "O'Connor",
			waitlist: true,
		} );
		expect( result.meta ).toMatchObject( {
			booker_email: 'person@example.test',
			booker_name: "O'Connor",
			booker_waitlist: true,
			waitlist_notified_slots: [],
			questions: [ { id: 'topic' } ],
		} );
		expect( result.slotParts ).toEqual( [
			'2099-01-01',
			'10:00',
			'10:30',
		] );
	} );

	it( 'restores required links in custom confirmation templates', () => {
		const result = getControllerResult();

		expect( result.plain ).toContain( '[confirmation_link]' );
		expect( result.plain ).toContain( '[imprint_link]' );
		expect( result.html ).toContain( 'href="[confirmation_link]"' );
		expect( result.html ).toContain( 'href="[imprint_link]"' );
	} );
} );
