import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type ScenarioResult = {
	events: Array< Array< string | number | boolean > >;
	deletedPending: string[];
	deletedCancel: string[];
	cancelledSlots: string[];
	enabledSlots: string[];
	disabledSlots: string[];
};

type CancellationResults = Record< string, ScenarioResult >;

const runCancellationScenarios = (): CancellationResults => {
	const controllerPath = resolve(
		process.cwd(),
		'includes/Controller/CancellationController.php'
	);
	const php = `
		namespace RRZE\\Appointment {
			class AppointmentException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Presentation {
			class PublicPageRenderer {
				public array $events = [];
				public function getAppointmentDetails( string $slot, array $meta ): array {
					return [ 'slot' => $slot, 'name' => $meta['name'] ?? '' ];
				}
				public function renderError( string $message, int $status ): void {
					$this->events[] = [ 'error', $status ];
				}
				public function renderCancellationConfirmation( string $token, array $details ): void {
					$this->events[] = [ 'confirmation', $token, $details['name'] ];
				}
				public function renderCancellationSuccess( array $details ): void {
					$this->events[] = [ 'cancelled', $details['slot'] ];
				}
				public function renderWaitlistStatus( string $token, bool $enabled, array $details ): void {
					$this->events[] = [ 'waitlist', $token, $enabled ];
				}
			}
		}
		namespace RRZE\\Appointment\\Booking {
			class TokenManager {
				public static array $deletedPending = [];
				public static array $deletedCancel = [];
				public static function validateCancelToken( string $token ) {
					$entries = [
						'pending-token' => [
							'type' => 'pending',
							'slot' => '2026-08-21 09:00-09:30',
							'pending_token' => 'pending-id',
						],
						'confirmed-token' => [
							'type' => 'confirmed',
							'slot' => '2026-08-22 10:00-10:30',
						],
					];
					return $entries[ $token ] ?? false;
				}
				public static function getPending( string $token ): array {
					return $token === 'pending-id' ? [ 'meta' => [ 'name' => 'Pending' ] ] : [];
				}
				public static function deletePending( string $token ): void {
					self::$deletedPending[] = $token;
				}
				public static function deleteCancelToken( string $token ): void {
					self::$deletedCancel[] = $token;
				}
				public static function validateWaitlistOptOutToken( string $token ): ?string {
					return $token === 'waitlist-token' ? '2026-08-22 10:00-10:30' : null;
				}
			}
			class Bookings {
				public const META_OPTION = 'booking_meta';
				public static array $cancelledSlots = [];
				public static array $enabledSlots = [];
				public static array $disabledSlots = [];
				public static function cancel( string $slot ): void {
					self::$cancelledSlots[] = $slot;
				}
				public static function enableWaitlistNotifications( string $slot ): bool {
					self::$enabledSlots[] = $slot;
					return true;
				}
				public static function disableWaitlistNotifications( string $slot ): bool {
					self::$disabledSlots[] = $slot;
					return true;
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['options'] = [
				'booking_meta' => [
					'2026-08-22 10:00-10:30' => [ 'name' => 'Confirmed' ],
				],
			];
			function __( $message, $domain ) { return $message; }
			function esc_html( $value ) { return $value; }
			function wp_die( $message, $title, $args ) { throw new \\RuntimeException( $message ); }
			function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
			function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
			function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $value ) ); }
			function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid:' . $action; }
			function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
			require ${ JSON.stringify( controllerPath ) };

			function run_scenario( array $get, array $post, string $method, string $handler ): array {
				$_GET = $get;
				$_POST = $post;
				$_SERVER['REQUEST_METHOD'] = $method;
				\\RRZE\\Appointment\\Booking\\TokenManager::$deletedPending = [];
				\\RRZE\\Appointment\\Booking\\TokenManager::$deletedCancel = [];
				\\RRZE\\Appointment\\Booking\\Bookings::$cancelledSlots = [];
				\\RRZE\\Appointment\\Booking\\Bookings::$enabledSlots = [];
				\\RRZE\\Appointment\\Booking\\Bookings::$disabledSlots = [];
				$renderer = new \\RRZE\\Appointment\\Presentation\\PublicPageRenderer();
				$controller = new \\RRZE\\Appointment\\Controller\\CancellationController( $renderer );
				$controller->$handler();
				return [
					'events' => $renderer->events,
					'deletedPending' => \\RRZE\\Appointment\\Booking\\TokenManager::$deletedPending,
					'deletedCancel' => \\RRZE\\Appointment\\Booking\\TokenManager::$deletedCancel,
					'cancelledSlots' => \\RRZE\\Appointment\\Booking\\Bookings::$cancelledSlots,
					'enabledSlots' => \\RRZE\\Appointment\\Booking\\Bookings::$enabledSlots,
					'disabledSlots' => \\RRZE\\Appointment\\Booking\\Bookings::$disabledSlots,
				];
			}

			$cancelAction = [ 'rrze_appt_cancel_action' => 'cancel' ];
			echo json_encode( [
				'confirmation' => run_scenario(
					[ 'rrze_appt_cancel' => 'confirmed-token' ], [], 'GET', 'handleCancellation'
				),
				'pending' => run_scenario(
					[ 'rrze_appt_cancel' => 'pending-token' ],
					$cancelAction + [ 'rrze_appt_cancel_nonce' => 'valid:rrze_appointment_cancel_pending-token' ],
					'POST',
					'handleCancellation'
				),
				'invalidNonce' => run_scenario(
					[ 'rrze_appt_cancel' => 'confirmed-token' ],
					$cancelAction + [ 'rrze_appt_cancel_nonce' => 'invalid' ],
					'POST',
					'handleCancellation'
				),
				'confirmed' => run_scenario(
					[ 'rrze_appt_cancel' => 'confirmed-token' ],
					$cancelAction + [ 'rrze_appt_cancel_nonce' => 'valid:rrze_appointment_cancel_confirmed-token' ],
					'POST',
					'handleCancellation'
				),
				'invalidQueryValue' => run_scenario(
					[ 'rrze_appt_cancel' => [ 'confirmed-token' ] ], [], 'GET', 'handleCancellation'
				),
				'waitlistOptOut' => run_scenario(
					[ 'rrze_appt_waitlist_optout' => 'waitlist-token' ], [], 'GET', 'handleWaitlistPreference'
				),
				'waitlistOptIn' => run_scenario(
					[ 'rrze_appt_waitlist_optout' => 'waitlist-token' ],
					[
						'rrze_appt_waitlist_action' => 'optin',
						'rrze_appt_waitlist_nonce' => 'valid:rrze_appointment_waitlist_optin_waitlist-token',
					],
					'POST',
					'handleWaitlistPreference'
				),
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as CancellationResults;
};

describe( 'cancellation controller', () => {
	it( 'requires an explicit nonce-protected POST before cancelling', () => {
		const results = runCancellationScenarios();

		expect( results.confirmation.events ).toEqual( [
			[ 'confirmation', 'confirmed-token', 'Confirmed' ],
		] );
		expect( results.confirmation.cancelledSlots ).toEqual( [] );
		expect( results.invalidNonce.events ).toEqual( [ [ 'error', 403 ] ] );
		expect( results.invalidNonce.cancelledSlots ).toEqual( [] );
		expect( results.invalidQueryValue.events ).toEqual( [] );
	} );

	it( 'removes pending and confirmed appointments through their own stores', () => {
		const results = runCancellationScenarios();

		expect( results.pending.deletedPending ).toEqual( [ 'pending-id' ] );
		expect( results.pending.deletedCancel ).toEqual( [] );
		expect( results.pending.events[ 0 ] ).toEqual( [
			'cancelled',
			'2026-08-21 09:00-09:30',
		] );
		expect( results.confirmed.deletedCancel ).toEqual( [
			'confirmed-token',
		] );
		expect( results.confirmed.cancelledSlots ).toEqual( [
			'2026-08-22 10:00-10:30',
		] );
	} );

	it( 'disables and explicitly re-enables waitlist notifications', () => {
		const results = runCancellationScenarios();

		expect( results.waitlistOptOut.disabledSlots ).toEqual( [
			'2026-08-22 10:00-10:30',
		] );
		expect( results.waitlistOptOut.events ).toEqual( [
			[ 'waitlist', 'waitlist-token', false ],
		] );
		expect( results.waitlistOptIn.enabledSlots ).toEqual( [
			'2026-08-22 10:00-10:30',
		] );
		expect( results.waitlistOptIn.disabledSlots ).toEqual( [] );
		expect( results.waitlistOptIn.events ).toEqual( [
			[ 'waitlist', 'waitlist-token', true ],
		] );
	} );
} );
