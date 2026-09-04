import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type ConfirmationResult = {
	invalidQueryToken: string;
	getRequest: unknown;
	invalidNonce: unknown;
	requiredError: unknown;
	validAnswers: Array< { label: string; answer: string } >;
	renderEvents: Array< Array< unknown > >;
	persistedMeta: Record< string, unknown >;
	sensitivePersistedMeta: Record< string, unknown >;
	slotParts: string[];
	escapedCalendarValue: string;
	calendar: string;
	attachmentPath: string;
	attachmentExistsAfterFailure: boolean;
};

const getConfirmationResult = (): ConfirmationResult => {
	const controllerPath = resolve(
		process.cwd(),
		'includes/Controller/ConfirmationController.php'
	);
	const php = `
		namespace RRZE\\Appointment {
			class AppointmentException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Presentation {
			class PublicPageRenderer {
				public array $events = [];
				public function renderConfirmation( ...$arguments ): void {
					$this->events[] = $arguments;
				}
				public function renderError( string $message, int $status ): void {}
			}
		}
		namespace RRZE\\Appointment\\Booking {
			class Bookings {
				public const SLOTS_OPTION = 'booked_slots';
				public const ADMIN_ANONYMIZED_META_KEY = 'admin_anonymized';
			}
		}
		namespace RRZE\\Appointment\\Configuration {
			class PluginSettings {
				public static bool $sensitiveModeEnabled = false;
				public static function get( string $key ) {
					return $key === 'sensitive_mode_enabled'
						? self::$sensitiveModeEnabled
						: null;
				}
			}
		}
		namespace RRZE\\Appointment\\Mail {
			class MailTemplate {
				public const STATUS_SUCCESS = 'success';
			}
			class MailTemplatePost {
				public static function getTemplateForType( int $id, string $type ): ?array {
					return null;
				}
				public static function getDefault( string $type ): array {
					return [
						'subject' => 'Subject',
						'body' => '[cancel_link] [imprint_link] [questions]',
						'body_html' => '[cancel_link] [imprint_link] [questions]',
					];
				}
			}
		}
		namespace RRZE\\Appointment\\Mail {
			class Mailer {
				public static string $attachmentPath = '';
				public static function render( string $template, array $variables ): string {
					return str_replace( array_keys( $variables ), array_values( $variables ), $template );
				}
				public static function send(
					string $to,
					string $subject,
					string $plain,
					string $html,
					array $attachments,
					string $status
				): bool {
					self::$attachmentPath = $attachments[0];
					throw new \\RuntimeException( 'mail failure' );
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			function __( $message, $domain ) { return $message; }
			function esc_html__( $message, $domain ) { return $message; }
			function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
			function wp_unslash( $value ) {
				if ( is_array( $value ) ) {
					return array_map( 'wp_unslash', $value );
				}
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
			function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
			function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
			function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $value ) ); }
			function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
			function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid:' . $action; }
			function wp_timezone() { return new \\DateTimeZone( 'Europe/Berlin' ); }
			function wp_generate_uuid4() { return 'test-uuid'; }
			function home_url( $path = '' ) { return 'https://example.test' . $path; }
			function get_temp_dir() { return sys_get_temp_dir(); }
			function wp_delete_file( $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
			require ${ JSON.stringify( controllerPath ) };

			$renderer = new \\RRZE\\Appointment\\Presentation\\PublicPageRenderer();
			$controller = new \\RRZE\\Appointment\\Controller\\ConfirmationController( $renderer );
			$reflection = new ReflectionClass( $controller );
			$getToken = $reflection->getMethod( 'getConfirmationToken' );
			$collect = $reflection->getMethod( 'collectQuestionAnswers' );
			$getMeta = $reflection->getMethod( 'getPersistableBookingMeta' );
			$parseSlot = $reflection->getMethod( 'parseSlot' );
			$escapeCalendar = $reflection->getMethod( 'escapeCalendarValue' );
			$createCalendar = $reflection->getMethod( 'createCalendarAttachment' );
			$sendEmails = $reflection->getMethod( 'sendConfirmationEmails' );

			$_GET = [ 'rrze_appt_confirm' => [ 'invalid' ] ];
			$invalidQueryToken = $getToken->invoke( $controller );
			$questions = [
				[ 'id' => 'topic', 'label' => 'Topic', 'type' => 'text', 'required' => true ],
				[ 'id' => 'format', 'label' => 'Format', 'type' => 'select', 'options' => [ 'Online', 'On site' ] ],
			];
			$details = [ 'title' => 'Consultation' ];

			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST = [];
			$getRequest = $collect->invoke( $controller, 'token', $questions, $details );
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST = [
				'rrze_appt_questions_nonce' => 'invalid',
				'question_answers' => [ 'topic' => 'Security', 'format' => 'Online' ],
			];
			$invalidNonce = $collect->invoke( $controller, 'token', $questions, $details );
			$_POST = [
				'rrze_appt_questions_nonce' => 'valid:rrze_appointment_confirm_questions_token',
				'question_answers' => [ 'topic' => '', 'format' => 'Online' ],
			];
			$requiredError = $collect->invoke( $controller, 'token', $questions, $details );
			$_POST['question_answers']['topic'] = "  <b>Security</b>\\'s scope  ";
			$validAnswers = $collect->invoke( $controller, 'token', $questions, $details );

			$persistedMeta = $getMeta->invoke( $controller, [
				'title' => 'Consultation',
				'booker_email' => 'person@example.test',
				'questions' => $questions,
				'untrusted' => 'discard',
			] );
			\\RRZE\\Appointment\\Configuration\\PluginSettings::$sensitiveModeEnabled = true;
			$sensitivePersistedMeta = $getMeta->invoke( $controller, [
				'title' => 'Sensitive consultation',
				'booker_name' => 'Private Person',
				'booker_email' => 'private@example.test',
				'untrusted' => 'discard',
			] );
			$slotParts = $parseSlot->invoke( $controller, '2026-08-21 09:00-09:30' );
			$escapedCalendarValue = $escapeCalendar->invoke(
				$controller,
				"Planning, review; room" . chr( 92 ) . "path\r\nInjected"
			);
			$calendar = $createCalendar->invoke(
				$controller,
				'2026-08-21',
				'09:00',
				'09:30',
				[ 'title' => 'Planning, review', 'location' => 'Room; 1' ]
			);

			try {
				$sendEmails->invoke(
					$controller,
					[ 'booker_email' => 'person@example.test' ],
					[ '[cancel_link]' => 'cancel', '[imprint_link]' => 'imprint', '[questions]' => '' ],
					[],
					$calendar
				);
			} catch ( \\ReflectionException | \\RuntimeException $exception ) {
				// The mail stub fails deliberately so cleanup can be asserted.
			}

			echo json_encode( [
				'invalidQueryToken' => $invalidQueryToken,
				'getRequest' => $getRequest,
				'invalidNonce' => $invalidNonce,
				'requiredError' => $requiredError,
				'validAnswers' => $validAnswers,
				'renderEvents' => $renderer->events,
				'persistedMeta' => $persistedMeta,
				'sensitivePersistedMeta' => $sensitivePersistedMeta,
				'slotParts' => $slotParts,
				'escapedCalendarValue' => $escapedCalendarValue,
				'calendar' => $calendar,
				'attachmentPath' => \\RRZE\\Appointment\\Mail\\Mailer::$attachmentPath,
				'attachmentExistsAfterFailure' => file_exists(
					\\RRZE\\Appointment\\Mail\\Mailer::$attachmentPath
				),
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as ConfirmationResult;
};

describe( 'confirmation controller', () => {
	it( 'renders question errors and only returns validated answers', () => {
		const result = getConfirmationResult();

		expect( result.invalidQueryToken ).toBe( '' );
		expect( result.getRequest ).toBeNull();
		expect( result.invalidNonce ).toBeNull();
		expect( result.requiredError ).toBeNull();
		expect( result.renderEvents ).toHaveLength( 3 );
		expect( result.renderEvents[ 1 ][ 3 ] ).toBe(
			'The form has expired. Please try again.'
		);
		expect( result.renderEvents[ 2 ][ 3 ] ).toBe(
			'Please answer “Topic”.'
		);
		expect( result.validAnswers ).toEqual( [
			{ label: 'Topic', answer: "Security's scope" },
			{ label: 'Format', answer: 'Online' },
		] );
	} );

	it( 'persists only approved metadata and parses stored slots', () => {
		const result = getConfirmationResult();

		expect( result.persistedMeta ).toEqual( {
			title: 'Consultation',
			booker_email: 'person@example.test',
		} );
		expect( result.sensitivePersistedMeta ).toEqual( {
			title: 'Sensitive consultation',
			booker_name: 'Private Person',
			booker_email: 'private@example.test',
			admin_anonymized: true,
		} );
		expect( result.slotParts ).toEqual( [
			'2026-08-21',
			'09:00',
			'09:30',
		] );
	} );

	it( 'creates safe calendar content and always removes its temporary file', () => {
		const result = getConfirmationResult();

		expect( result.escapedCalendarValue ).toBe(
			'Planning\\, review\\; room\\\\path\\nInjected'
		);
		expect( result.calendar ).toContain( 'BEGIN:VCALENDAR\r\n' );
		expect( result.calendar ).toContain( 'DTSTART:20260821T070000Z\r\n' );
		expect( result.calendar ).toContain( 'SUMMARY:Planning\\, review' );
		expect( result.attachmentPath ).toMatch( /\.ics$/ );
		expect( result.attachmentExistsAfterFailure ).toBe( false );
	} );
} );
