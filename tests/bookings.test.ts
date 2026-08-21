import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type BookingsResult = {
	bookings: Array< Record< string, unknown > >;
	removed: number;
	remainingSlots: string[];
	remainingMetaKeys: string[];
	cancelTokenKeys: string[];
	pendingTokenKeys: string[];
	clearedHooks: string[];
};

const runBookingsScenario = (): BookingsResult => {
	const bookingsPath = resolve(
		process.cwd(),
		'includes/Booking/Bookings.php'
	);
	const php = `
		namespace RRZE\\Appointment\\Common {
			class CustomException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Notification {
			class Reminder {
				public const CRON_HOOK = 'reminder_hook';
			}
		}
		namespace RRZE\\Appointment\\Booking {
			class TokenManager {
				public const CANCEL_OPTION = 'cancel_tokens';
				public const PENDING_OPTION = 'pending_tokens';
				public const PENDING_EXPIRY_HOOK = 'pending_expiry_hook';
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			define( 'MINUTE_IN_SECONDS', 60 );
			$GLOBALS['options'] = [
				'rrze_appointment_booked_slots' => [
					'2026-08-22 11:00-11:30',
					'2026-08-19 09:00-09:30',
					'2026-08-21 10:00-10:30',
				],
				'rrze_appointment_booked_slots_meta' => [
					'2026-08-19 09:00-09:30' => [ 'title' => 'Past', 'person_id' => 1 ],
					'2026-08-21 10:00-10:30' => [
						'title' => 'First',
						'person_id' => 1,
						'person_name' => 'Host One',
						'booker_name' => 'Booker',
						'booker_email' => 'booker@example.test',
					],
					'2026-08-22 11:00-11:30' => [
						'title' => 'Second',
						'person_id' => 2,
						'person_name' => 'Host Two',
					],
				],
				'cancel_tokens' => [
					'past-cancel' => [ 'slot' => '2026-08-19 09:00-09:30' ],
					'future-cancel' => [ 'slot' => '2026-08-21 10:00-10:30' ],
				],
				'pending_tokens' => [
					'past-pending' => [ 'slot' => '2026-08-19 09:00-09:30' ],
					'future-pending' => [ 'slot' => '2026-08-21 10:00-10:30' ],
				],
			];
			$GLOBALS['cleared_hooks'] = [];
			function get_option( $key, $default = false ) {
				return $GLOBALS['options'][ $key ] ?? $default;
			}
			function update_option( $key, $value, $autoload = null ) {
				$GLOBALS['options'][ $key ] = $value;
				return true;
			}
			function delete_option( $key ) {
				unset( $GLOBALS['options'][ $key ] );
				return true;
			}
			function wp_clear_scheduled_hook( $hook, $args ) {
				$GLOBALS['cleared_hooks'][] = $hook . ':' . $args[0];
			}
			function current_datetime() {
				return new DateTimeImmutable( '2026-08-20 12:00', new DateTimeZone( 'UTC' ) );
			}
			function current_time( $type ) {
				return $type === 'timestamp'
					? ( new DateTimeImmutable( '2026-08-20 12:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp()
					: '2026-08-20';
			}
			function wp_timezone() {
				return new DateTimeZone( 'UTC' );
			}
			function sanitize_email( $value ) {
				return filter_var( $value, FILTER_SANITIZE_EMAIL );
			}
			function get_post_meta( $postId, $key, $single ) {
				return '';
			}
			function get_the_title( $postId ) {
				return '';
			}
			require ${ JSON.stringify( bookingsPath ) };
			$bookings = \\RRZE\\Appointment\\Booking\\Bookings::getAll( [
				'date_from' => '2026-08-21',
				'date_to' => '2026-08-21',
				'person_id' => 1,
			] );
			$removed = \\RRZE\\Appointment\\Booking\\Bookings::cleanupExpired( 0 );
			echo json_encode( [
				'bookings' => $bookings,
				'removed' => $removed,
				'remainingSlots' => get_option( 'rrze_appointment_booked_slots', [] ),
				'remainingMetaKeys' => array_keys( get_option( 'rrze_appointment_booked_slots_meta', [] ) ),
				'cancelTokenKeys' => array_keys( get_option( 'cancel_tokens', [] ) ),
				'pendingTokenKeys' => array_keys( get_option( 'pending_tokens', [] ) ),
				'clearedHooks' => $GLOBALS['cleared_hooks'],
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as BookingsResult;
};

describe( 'bookings persistence', () => {
	it( 'filters future bookings and removes all state for expired slots', () => {
		const result = runBookingsScenario();

		expect( result.bookings ).toHaveLength( 1 );
		expect( result.bookings[ 0 ] ).toMatchObject( {
			slot: '2026-08-21 10:00-10:30',
			date: '2026-08-21',
			time: '10:00-10:30',
			person_id: 1,
			person_name: 'Host One',
		} );
		expect( result.removed ).toBe( 1 );
		expect( result.remainingSlots ).toEqual( [
			'2026-08-22 11:00-11:30',
			'2026-08-21 10:00-10:30',
		] );
		expect( result.remainingMetaKeys ).not.toContain(
			'2026-08-19 09:00-09:30'
		);
		expect( result.cancelTokenKeys ).toEqual( [ 'future-cancel' ] );
		expect( result.pendingTokenKeys ).toEqual( [ 'future-pending' ] );
		expect( result.clearedHooks ).toEqual(
			expect.arrayContaining( [
				'reminder_hook:2026-08-19 09:00-09:30',
				'pending_expiry_hook:past-pending',
			] )
		);
	} );
} );
