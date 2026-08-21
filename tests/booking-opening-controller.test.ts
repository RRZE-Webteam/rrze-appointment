import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type ControllerResult = {
	request: Record< string, unknown >;
	meta: Record< string, unknown >;
};

const getControllerResult = (): ControllerResult => {
	const controllerPath = resolve(
		process.cwd(),
		'includes/Controller/BookingOpeningController.php'
	);
	const php = `
		namespace RRZE\\Appointment {
			class AppointmentException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Presentation {
			class PublicPageRenderer {}
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
			$_POST = [
				'slot' => ' 2099-01-01 10:00-10:30 ',
				'post_id' => '42',
				'block_id' => '<b>fingerprint</b>',
				'booker_email' => 'person@example.test',
				'booker_name' => "O\\\\'Connor",
			];
			require ${ JSON.stringify( controllerPath ) };
			$controller = new \\RRZE\\Appointment\\Controller\\BookingOpeningController(
				new \\RRZE\\Appointment\\Presentation\\PublicPageRenderer()
			);
			$reflection = new ReflectionClass( $controller );
			$getRequestData = $reflection->getMethod( 'getRequestData' );
			$buildMeta = $reflection->getMethod( 'buildSubscriptionMeta' );
			$context = [
				'title' => 'Consultation',
				'location' => 'Room 1',
				'person_id' => 7,
				'person_name' => 'Host',
				'person_email' => 'host@example.test',
				'tpl_id' => 9,
				'post_link' => 'https://example.test/appointment',
				'questions' => [ [ 'id' => 'topic' ] ],
				'disable_sso' => true,
				'booking_opens_at' => 100,
			];
			echo json_encode( [
				'request' => $getRequestData->invoke( $controller ),
				'meta' => $buildMeta->invoke(
					$controller,
					$context,
					'person@example.test',
					"O'Connor"
				),
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as ControllerResult;
};

describe( 'booking opening controller', () => {
	it( 'sanitizes request data and stores only subscription metadata', () => {
		const result = getControllerResult();

		expect( result.request ).toEqual( {
			slot: '2099-01-01 10:00-10:30',
			postId: 42,
			blockFingerprint: 'fingerprint',
			email: 'person@example.test',
			name: "O'Connor",
		} );
		expect( result.meta ).toEqual( {
			title: 'Consultation',
			location: 'Room 1',
			person_id: 7,
			person_name: 'Host',
			person_email: 'host@example.test',
			booker_email: 'person@example.test',
			booker_name: "O'Connor",
			booker_waitlist: false,
			waitlist_notified_slots: [],
			tpl_id: 9,
			post_link: 'https://example.test/appointment',
			questions: [ { id: 'topic' } ],
		} );
	} );
} );
