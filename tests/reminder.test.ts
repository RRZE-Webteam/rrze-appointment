import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type Mail = {
	to: string;
	subject: string;
	plain: string;
	html: string;
	status: string;
};

type ReminderResult = {
	actions: string[];
	dailySchedule: { timestamp: number; recurrence: string; hook: string };
	singleSchedule: { timestamp: number; hook: string; args: string[] };
	storedFutureMeta: Record< string, unknown >;
	mails: Mail[];
	dailyMails: Mail[];
	cleanupRetention: number;
};

const getReminderResult = (): ReminderResult => {
	const reminderPath = resolve( process.cwd(), 'includes/Reminder.php' );
	const php = `
		namespace RRZE\\Appointment\\Common {
			class CustomException extends \\Exception {}
		}
		namespace RRZE\\Appointment {
			class Bookings {
				public const META_OPTION = 'booking_meta';
				public static int $cleanupRetention = -1;
				public static function cleanupExpired( int $days ): int {
					self::$cleanupRetention = $days;
					return 0;
				}
			}
			class Settings {
				public static array $mails = [];
				public static function get( string $key ) {
					return [ 'reminder_days' => 2, 'retention_days' => 30 ][ $key ] ?? null;
				}
				public static function renderTemplate( string $template, array $variables ): string {
					return str_replace( array_keys( $variables ), array_values( $variables ), $template );
				}
				public static function sendMail(
					string $to,
					string $subject,
					string $plain,
					string $html,
					array $attachments,
					string $status
				): bool {
					self::$mails[] = compact( 'to', 'subject', 'plain', 'html', 'status' );
					return true;
				}
			}
			class TokenManager {
				public static function getCancelUrlForSlot( string $slot ): string {
					return 'https://example.test/cancel?a=1&slot=' . rawurlencode( $slot );
				}
				public static function imprintUrl(): string { return 'https://example.test/legal'; }
			}
			class MailTemplatePost {
				public static function getTemplateForType( int $id, string $type ): ?array {
					if ( $id !== 5 ) { return null; }
					return $type === 'reminder_admin'
						? [ 'subject' => 'Custom admin [title]', 'body' => '', 'body_html' => '' ]
						: [ 'subject' => '', 'body' => 'Custom booker [name]', 'body_html' => '' ];
				}
				public static function getDefault( string $type ): array {
					$prefix = $type === 'reminder_admin' ? 'Admin' : 'Booker';
					return [
						'subject' => $prefix . ' [title]',
						'body' => $prefix . ' plain [name]',
						'body_html' => '<p>' . $prefix . ' [name] [cancel_link]</p>',
					];
				}
			}
			class MailTemplate {
				public static function statusForType( string $type ): string { return 'status:' . $type; }
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['actions'] = [];
			$GLOBALS['daily_schedule'] = null;
			$GLOBALS['single_schedule'] = null;
			$GLOBALS['options'] = [
				'booking_meta' => [
					'2026-08-23 10:00-10:30' => [
						'title' => '<b>Security review</b>',
						'location' => 'Room <i>1</i>',
						'person_id' => 2,
						'person_email' => 'host@example.test',
						'booker_email' => 'booker@example.test',
						'booker_name' => 'Alice & Bob',
						'tpl_id' => 5,
					],
				],
			];
			function __( $value, $domain ) { return $value; }
			function add_action( $hook, $callback, $priority = 10, $acceptedArgs = 1 ) {
				$GLOBALS['actions'][] = $hook;
			}
			function wp_next_scheduled( $hook ) { return false; }
			function wp_schedule_event( $timestamp, $recurrence, $hook ) {
				$GLOBALS['daily_schedule'] = compact( 'timestamp', 'recurrence', 'hook' );
			}
			function wp_schedule_single_event( $timestamp, $hook, $args ) {
				$GLOBALS['single_schedule'] = compact( 'timestamp', 'hook', 'args' );
			}
			function current_datetime() {
				return new \\DateTimeImmutable( '2026-08-21 06:00:00', new \\DateTimeZone( 'Europe/Berlin' ) );
			}
			function wp_timezone() { return new \\DateTimeZone( 'Europe/Berlin' ); }
			function get_option( $key, $default = false ) {
				if ( $key === 'date_format' ) { return 'd.m.Y'; }
				return $GLOBALS['options'][ $key ] ?? $default;
			}
			function update_option( $key, $value, $autoload = null ) {
				$GLOBALS['options'][ $key ] = $value;
				return true;
			}
			function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
			function sanitize_email( $value ) {
				return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
			}
			function esc_url_raw( $value ) { return $value; }
			function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
			function home_url( $path = '' ) { return 'https://example.test' . $path; }
			function date_i18n( $format, $timestamp ) { return date( $format, $timestamp ); }
			function get_post_meta( $id, $key, $single ) {
				return [
					'person_honorificPrefix' => 'Prof.',
					'person_givenName' => 'Grace',
					'person_familyName' => 'Hopper',
				][ $key ] ?? '';
			}
			require ${ JSON.stringify( reminderPath ) };

			$reminder = new \\RRZE\\Appointment\\Reminder();
			$reminder->register();
			\\RRZE\\Appointment\\Reminder::scheduleForSlot(
				'2099-09-01 08:00-08:30',
				[ 'title' => 'Future' ]
			);
			$reminder->sendReminder( '2026-08-23 10:00-10:30' );
			$mails = \\RRZE\\Appointment\\Settings::$mails;
			\\RRZE\\Appointment\\Settings::$mails = [];
			$reminder->checkAndSendReminders();
			echo json_encode( [
				'actions' => $GLOBALS['actions'],
				'dailySchedule' => $GLOBALS['daily_schedule'],
				'singleSchedule' => $GLOBALS['single_schedule'],
				'storedFutureMeta' => $GLOBALS['options']['booking_meta']['2099-09-01 08:00-08:30'],
				'mails' => $mails,
				'dailyMails' => \\RRZE\\Appointment\\Settings::$mails,
				'cleanupRetention' => \\RRZE\\Appointment\\Bookings::$cleanupRetention,
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as ReminderResult;
};

describe( 'appointment reminders', () => {
	it( 'registers site-local daily and slot-specific schedules', () => {
		const result = getReminderResult();

		expect( result.actions ).toEqual( [
			'rrze_appointment_send_reminder',
			'rrze_appointment_daily_check',
		] );
		expect( result.dailySchedule ).toMatchObject( {
			recurrence: 'daily',
			hook: 'rrze_appointment_daily_check',
		} );
		expect( result.singleSchedule ).toMatchObject( {
			hook: 'rrze_appointment_send_reminder',
			args: [ '2099-09-01 08:00-08:30' ],
		} );
		expect( result.storedFutureMeta ).toEqual( { title: 'Future' } );
	} );

	it( 'renders host and booker templates independently with safe HTML values', () => {
		const result = getReminderResult();

		expect( result.mails ).toHaveLength( 2 );
		expect( result.mails[ 0 ] ).toMatchObject( {
			to: 'host@example.test',
			subject: 'Custom admin Security review',
			plain: 'Admin plain Alice & Bob',
			status: 'status:reminder_admin',
		} );
		expect( result.mails[ 0 ].html ).toContain( 'Alice &amp; Bob' );
		expect( result.mails[ 0 ].html ).toContain( '&amp;slot=' );
		expect( result.mails[ 1 ] ).toMatchObject( {
			to: 'booker@example.test',
			plain: 'Custom booker Alice & Bob',
			status: 'status:reminder_booker',
		} );
	} );

	it( 'runs retention cleanup before the daily timezone-aware fallback', () => {
		const result = getReminderResult();

		expect( result.cleanupRetention ).toBe( 30 );
		expect( result.dailyMails ).toHaveLength( 2 );
	} );
} );
