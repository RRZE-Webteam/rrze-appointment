import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type ViewContext = Record< string, unknown > & {
	illustrationUrl: string;
};

type RendererResult = {
	details: Record< string, string >;
	invalidDateDetails: Record< string, string >;
	question: ViewContext;
	confirmed: ViewContext;
	cancellationSuccess: ViewContext;
	cancellationConfirmation: ViewContext;
	waitlist: ViewContext;
	opening: ViewContext;
};

const getRendererResult = (): RendererResult => {
	const rendererPath = resolve(
		process.cwd(),
		'includes/Presentation/PublicPageRenderer.php'
	);
	const php = `
		namespace RRZE\\Appointment\\Booking {
			class TokenManager {
				public static function confirmUrl( string $token ): string { return 'confirm:' . $token; }
				public static function cancelUrl( string $token ): string { return 'cancel:' . $token; }
				public static function waitlistOptOutUrl( string $token ): string { return 'waitlist:' . $token; }
			}
		}
		namespace RRZE\\Appointment {
			function plugin() {
				return new class {
					public function getUrl( string $path ): string { return 'https://assets.test/' . $path . '/'; }
					public function getPath( string $path ): string { return '/unused/' . $path . '/'; }
				};
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
			function wp_timezone() { return new \\DateTimeZone( 'Europe/Berlin' ); }
			function get_option( $key ) { return 'd.m.Y'; }
			function wp_date( $format, $timestamp, $timezone ) {
				return ( new \\DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format );
			}
			function home_url( $path = '' ) { return 'https://example.test' . $path; }
			function get_bloginfo( $key ) { return $key === 'name' ? 'Example Site' : ''; }
			function wp_create_nonce( $action ) { return 'nonce:' . $action; }
			require ${ JSON.stringify( rendererPath ) };

			$renderer = new \\RRZE\\Appointment\\Presentation\\PublicPageRenderer();
			$reflection = new ReflectionClass( $renderer );
			$buildContext = $reflection->getMethod( 'buildConfirmationContext' );
			$build = static function ( string $mode, array $data ) use ( $renderer, $buildContext ): array {
				return $buildContext->invoke( $renderer, $mode, $data );
			};
			echo json_encode( [
				'details' => $renderer->getAppointmentDetails(
					'2026-08-21 <b>09:00</b>-<i>09:30</i>',
					[ 'title' => '<b>Consultation</b>', 'location' => '<i>Room 1</i>' ]
				),
				'invalidDateDetails' => $renderer->getAppointmentDetails( '2026-02-30 10:00-10:30' ),
				'question' => $build( 'confirmation', [
					'token' => 'question-token',
					'questions' => [ [ 'id' => 'topic' ] ],
					'submittedAnswers' => [ 'topic' => 'Security' ],
					'formError' => 'Required',
					'formErrorField' => 'topic',
					'appointmentDetails' => [ 'title' => 'Consultation' ],
				] ),
				'confirmed' => $build( 'confirmation', [] ),
				'cancellationSuccess' => $build( 'cancellation_success', [] ),
				'cancellationConfirmation' => $build( 'cancellation_confirmation', [
					'token' => 'cancel-token',
					'showCancellationReason' => true,
				] ),
				'waitlist' => $build( 'waitlist', [
					'token' => 'waitlist-token',
					'notificationsEnabled' => true,
				] ),
				'opening' => $build( 'opening_notification', [] ),
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as RendererResult;
};

describe( 'public page renderer', () => {
	it( 'builds sanitized non-personal appointment details', () => {
		const result = getRendererResult();

		expect( result.details ).toEqual( {
			title: 'Consultation',
			date: '21.08.2026',
			time: '09:00 – 09:30',
			location: 'Room 1',
		} );
		expect( result.invalidDateDetails ).toMatchObject( { date: '' } );
	} );

	it( 'builds a complete question-form context', () => {
		const question = getRendererResult().question;

		expect( question ).toMatchObject( {
			isQuestionForm: true,
			isCancellation: false,
			isCancellationConfirmation: false,
			isWaitlistOptOut: false,
			isOpeningNotification: false,
			formAction: 'confirm:question-token',
			formNonce:
				'nonce:rrze_appointment_confirm_questions_question-token',
			formError: 'Required',
			formErrorField: 'topic',
		} );
		expect( question.illustrationUrl ).toContain(
			'financial-analyst-31.png'
		);
	} );

	it( 'builds isolated contexts for every public status page', () => {
		const result = getRendererResult();

		expect( result.confirmed ).toMatchObject( {
			isQuestionForm: false,
			isCancellation: false,
			isOpeningNotification: false,
		} );
		expect( result.cancellationSuccess ).toMatchObject( {
			isCancellation: true,
			isCancellationConfirmation: false,
		} );
		expect( result.cancellationConfirmation ).toMatchObject( {
			isCancellation: false,
			isCancellationConfirmation: true,
			cancellationAction: 'cancel:cancel-token',
			cancellationNonce: 'nonce:rrze_appointment_cancel_cancel-token',
			showCancellationReason: true,
		} );
		expect( result.waitlist ).toMatchObject( {
			isWaitlistOptOut: true,
			waitlistNotificationsEnabled: true,
			waitlistOptInAction: 'waitlist:waitlist-token',
			waitlistOptInNonce:
				'nonce:rrze_appointment_waitlist_optin_waitlist-token',
		} );
		expect( result.opening ).toMatchObject( {
			isOpeningNotification: true,
		} );
		expect( result.opening.illustrationUrl ).toContain(
			'notification-36.png'
		);
	} );
} );
