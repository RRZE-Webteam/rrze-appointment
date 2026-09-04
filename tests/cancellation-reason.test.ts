import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type SentMail = {
	to: string;
	plain: string;
	html: string;
};

type CancellationReasonResult = {
	withReason: SentMail[];
	withoutReason: SentMail[];
};

const getCancellationReasonResult = (): CancellationReasonResult => {
	const bookingsPath = resolve(
		process.cwd(),
		'includes/Booking/Bookings.php'
	);
	const php = `
		namespace RRZE\\Appointment {
			class AppointmentException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Notification {
			class Reminder { public const CRON_HOOK = 'reminder'; }
		}
		namespace RRZE\\Appointment\\Booking {
			class TokenManager {
				public const CANCEL_OPTION = 'cancel';
				public const PENDING_OPTION = 'pending';
				public const PENDING_EXPIRY_HOOK = 'pending_expiry';
				public static function imprintUrl(): string { return 'https://example.test/legal'; }
			}
		}
		namespace RRZE\\Appointment\\Mail {
			class MailTemplate {
				public const STATUS_NEUTRAL = 'neutral';
				public const STATUS_DANGER = 'danger';
			}
			class MailTemplatePost {
				public static function getTemplateForType( int $id, string $type ): array {
					return [
						'subject' => 'Cancellation [title]',
						'body' => 'Cancelled. [imprint_link]',
						'body_html' => '<p>Cancelled.</p><a href="[imprint_link]">Legal</a>',
					];
				}
				public static function getDefault( string $type ): array { return []; }
			}
			class Mailer {
				public static array $mails = [];
				public static function render( string $template, array $variables ): string {
					return strtr( $template, $variables );
				}
				public static function send(
					string $to,
					string $subject,
					string $plain,
					string $html,
					array $attachments = [],
					string $status = 'neutral'
				): bool {
					self::$mails[] = compact( 'to', 'plain', 'html' );
					return true;
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			define( 'MINUTE_IN_SECONDS', 60 );
			function __( $value, $domain ) { return $value; }
			function esc_html__( $value, $domain ) { return esc_html( $value ); }
			function esc_html( $value ) {
				return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			}
			function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
			function sanitize_email( $value ) { return filter_var( $value, FILTER_SANITIZE_EMAIL ); }
			function esc_url_raw( $value ) { return $value; }
			function home_url( $path = '' ) { return 'https://example.test' . $path; }
			function get_option( $key, $default = false ) {
				return $key === 'date_format' ? 'Y-m-d' : $default;
			}
			function date_i18n( $format, $timestamp ) { return date( $format, $timestamp ); }
			require ${ JSON.stringify( bookingsPath ) };

			$method = ( new ReflectionClass( \\RRZE\\Appointment\\Booking\\Bookings::class ) )
				->getMethod( 'sendCancellationMail' );
			$meta = [
				'tpl_id' => 7,
				'title' => 'Consultation',
				'person_id' => 1,
				'person_name' => 'Host',
				'person_email' => 'host@example.test',
				'booker_name' => 'Booker',
				'booker_email' => 'booker@example.test',
			];
			$method->invoke(
				null,
				'2026-09-10 10:00-10:30',
				$meta,
				"  Room <b>changed</b> & use \\"A\\"\\nSecond line  "
			);
			$withReason = \\RRZE\\Appointment\\Mail\\Mailer::$mails;
			\\RRZE\\Appointment\\Mail\\Mailer::$mails = [];
			$method->invoke( null, '2026-09-10 10:00-10:30', $meta, '   ' );
			echo json_encode( [
				'withReason' => $withReason,
				'withoutReason' => \\RRZE\\Appointment\\Mail\\Mailer::$mails,
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as CancellationReasonResult;
};

describe( 'cancellation reason emails', () => {
	it( 'adds a sanitized reason to host and booker messages', () => {
		const mails = getCancellationReasonResult().withReason;

		expect( mails.map( ( mail ) => mail.to ) ).toEqual( [
			'host@example.test',
			'booker@example.test',
		] );
		for ( const mail of mails ) {
			expect( mail.plain ).toContain(
				'Reason for cancellation: Room changed & use "A"\nSecond line'
			);
			expect( mail.html ).toContain( 'Reason for cancellation</h2>' );
			expect( mail.html ).toContain(
				'Room changed &amp; use &quot;A&quot;<br />\nSecond line'
			);
			expect( mail.html ).not.toContain( '<b>changed</b>' );
		}
	} );

	it( 'does not show an empty reason section', () => {
		for ( const mail of getCancellationReasonResult().withoutReason ) {
			expect( mail.plain ).not.toContain( 'Reason for cancellation' );
			expect( mail.html ).not.toContain( 'Reason for cancellation' );
		}
	} );
} );
