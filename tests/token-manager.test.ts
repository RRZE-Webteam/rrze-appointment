import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type TokenManagerResult = {
	cleanedPending: Record< string, Record< string, unknown > >;
	cleanedTokens: Record< string, unknown >;
	clearedTokens: string[];
	createdPendingToken: string;
	scheduledExpiry: {
		timestamp: number;
		hook: string;
		arguments: string[];
	};
	pendingCancellation: Record< string, unknown >;
	confirmedPending: Record< string, unknown >;
	pendingRemoved: boolean;
	pendingCancellationRemoved: boolean;
	legacyCancellation: Record< string, unknown >;
	malformedCancellation: null;
	emptyCancellation: null;
	cancelUrl: string;
	waitlistUrl: string;
	waitlistSlot: string;
	unknownWaitlistSlot: null;
	actionUrls: string[];
	imprintUrl: string;
	corruptOptionsIgnored: string[];
	wrappedFailure: { className: string; message: string };
};

const getTokenManagerResult = (): TokenManagerResult => {
	const tokenManagerPath = resolve(
		process.cwd(),
		'includes/Booking/TokenManager.php'
	);
	const php = `
		namespace RRZE\\Appointment\\Common {
			class CustomException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Booking {
			class Bookings {
				public const META_OPTION = 'rrze_appointment_booked_slots_meta';
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$now = time();
			$GLOBALS['options'] = [
				'rrze_appointment_pending_slots' => [
					'pending-valid' => [
						'slot' => '2099-01-02 10:00-10:30',
						'meta' => [ 'title' => 'Valid' ],
						'expires' => $now + 300,
					],
					'pending-expired' => [
						'slot' => '2099-01-02 11:00-11:30',
						'meta' => [ 'title' => 'Expired' ],
						'expires' => $now,
					],
					'pending-invalid-meta' => [
						'slot' => '2099-01-02 12:00-12:30',
						'meta' => 'invalid',
						'expires' => $now + 300,
					],
				],
				'rrze_appointment_cancel_tokens' => [
					'legacy-pending-cancel' => [
						'type' => 'pending',
						'slot' => '2099-01-02 10:00-10:30',
						'meta' => [ 'duplicate' => true ],
					],
					'orphan-pending-cancel' => [
						'type' => 'pending',
						'pending_token' => 'missing',
					],
					'legacy-booked' => '2099-01-03 10:00-10:30',
					'malformed' => [ 'type' => 'booked', 'slot' => [ 'invalid' ] ],
				],
				'rrze_appointment_booked_slots_meta' => [
					'2099-01-03 10:00-10:30' => [ 'title' => 'Confirmed' ],
				],
			];
			$GLOBALS['cleared_tokens'] = [];
			$GLOBALS['scheduled_expiry'] = null;
			$GLOBALS['uuid_counter'] = 0;
			$GLOBALS['throw_on_get'] = false;

			function get_option( $key, $default = false ) {
				if ( $GLOBALS['throw_on_get'] ) { throw new \\RuntimeException( 'storage failed' ); }
				return array_key_exists( $key, $GLOBALS['options'] )
					? $GLOBALS['options'][ $key ]
					: $default;
			}
			function update_option( $key, $value, $autoload = null ) {
				$GLOBALS['options'][ $key ] = $value;
				return true;
			}
			function wp_clear_scheduled_hook( $hook, $arguments = [] ) {
				$GLOBALS['cleared_tokens'][] = (string) ( $arguments[0] ?? '' );
			}
			function wp_schedule_single_event( $timestamp, $hook, $arguments = [] ) {
				$GLOBALS['scheduled_expiry'] = compact( 'timestamp', 'hook', 'arguments' );
			}
			function wp_generate_uuid4() {
				$GLOBALS['uuid_counter']++;
				return 'uuid-' . $GLOBALS['uuid_counter'];
			}
			function wp_salt( $scheme = 'auth' ) { return 'salt-' . $scheme; }
			function wp_hash( $data, $scheme = 'auth' ) { return hash( 'sha256', $scheme . '|' . $data ); }
			function add_query_arg( $key, $value, $url ) {
				return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value );
			}
			function home_url( $path = '' ) { return 'https://example.test' . $path; }
			function get_page_by_path( $slug ) { return null; }
			function get_permalink( $postId ) { return 'https://example.test/page/' . $postId; }

			require ${ JSON.stringify( tokenManagerPath ) };

			\\RRZE\\Appointment\\Booking\\TokenManager::cleanupPendingState();
			$cleanedPending = $GLOBALS['options']['rrze_appointment_pending_slots'];
			$cleanedTokens = $GLOBALS['options']['rrze_appointment_cancel_tokens'];

			$createdPendingToken = \\RRZE\\Appointment\\Booking\\TokenManager::createPending(
				'2099-01-04 10:00-10:30',
				[ 'title' => 'Created' ]
			);
			$pendingCancelToken = \\RRZE\\Appointment\\Booking\\TokenManager::createPendingCancelToken(
				$createdPendingToken
			);
			$pendingCancellation = \\RRZE\\Appointment\\Booking\\TokenManager::validateCancelToken(
				$pendingCancelToken
			);
			$confirmedPending = \\RRZE\\Appointment\\Booking\\TokenManager::confirmPending(
				$createdPendingToken
			);
			$pendingRemoved = !isset(
				$GLOBALS['options']['rrze_appointment_pending_slots'][ $createdPendingToken ]
			);
			$pendingCancellationRemoved = !isset(
				$GLOBALS['options']['rrze_appointment_cancel_tokens'][ $pendingCancelToken ]
			);

			$legacyCancellation = \\RRZE\\Appointment\\Booking\\TokenManager::validateCancelToken(
				'legacy-booked'
			);
			$malformedCancellation = \\RRZE\\Appointment\\Booking\\TokenManager::validateCancelToken(
				'malformed'
			);
			$emptyCancellation = \\RRZE\\Appointment\\Booking\\TokenManager::validateCancelToken( '' );
			$cancelUrl = \\RRZE\\Appointment\\Booking\\TokenManager::getCancelUrlForSlot(
				'2099-01-03 10:00-10:30'
			);
			$waitlistUrl = \\RRZE\\Appointment\\Booking\\TokenManager::getWaitlistOptOutUrlForSlot(
				'2099-01-03 10:00-10:30'
			);
			$waitlistToken = $GLOBALS['options']['rrze_appointment_booked_slots_meta']
				['2099-01-03 10:00-10:30']['waitlist_optout_token'];
			$waitlistSlot = \\RRZE\\Appointment\\Booking\\TokenManager::validateWaitlistOptOutToken(
				$waitlistToken
			);
			$unknownWaitlistSlot = \\RRZE\\Appointment\\Booking\\TokenManager::validateWaitlistOptOutToken(
				'unknown'
			);
			$actionUrls = [
				\\RRZE\\Appointment\\Booking\\TokenManager::confirmUrl( 'confirm-token' ),
				\\RRZE\\Appointment\\Booking\\TokenManager::cancelUrl( 'cancel-token' ),
				\\RRZE\\Appointment\\Booking\\TokenManager::waitlistOptOutUrl( 'waitlist-token' ),
			];
			$imprintUrl = \\RRZE\\Appointment\\Booking\\TokenManager::imprintUrl();

			$GLOBALS['options']['rrze_appointment_pending_slots'] = 'corrupt';
			$corruptOptionsIgnored = \\RRZE\\Appointment\\Booking\\TokenManager::getPendingSlots();
			$GLOBALS['throw_on_get'] = true;
			$wrappedFailure = [];
			try {
				\\RRZE\\Appointment\\Booking\\TokenManager::getPendingSlots();
			} catch ( \\Throwable $exception ) {
				$wrappedFailure = [
					'className' => get_class( $exception ),
					'message' => $exception->getMessage(),
				];
			}

			echo json_encode( [
				'cleanedPending' => $cleanedPending,
				'cleanedTokens' => $cleanedTokens,
				'clearedTokens' => $GLOBALS['cleared_tokens'],
				'createdPendingToken' => $createdPendingToken,
				'scheduledExpiry' => $GLOBALS['scheduled_expiry'],
				'pendingCancellation' => $pendingCancellation,
				'confirmedPending' => $confirmedPending,
				'pendingRemoved' => $pendingRemoved,
				'pendingCancellationRemoved' => $pendingCancellationRemoved,
				'legacyCancellation' => $legacyCancellation,
				'malformedCancellation' => $malformedCancellation,
				'emptyCancellation' => $emptyCancellation,
				'cancelUrl' => $cancelUrl,
				'waitlistUrl' => $waitlistUrl,
				'waitlistSlot' => $waitlistSlot,
				'unknownWaitlistSlot' => $unknownWaitlistSlot,
				'actionUrls' => $actionUrls,
				'imprintUrl' => $imprintUrl,
				'corruptOptionsIgnored' => $corruptOptionsIgnored,
				'wrappedFailure' => $wrappedFailure,
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as TokenManagerResult;
};

describe( 'token manager', () => {
	it( 'removes invalid pending state and migrates linked legacy tokens', () => {
		const result = getTokenManagerResult();

		expect( Object.keys( result.cleanedPending ) ).toEqual( [
			'pending-valid',
		] );
		expect( result.cleanedPending[ 'pending-valid' ] ).toMatchObject( {
			cancel_token: 'legacy-pending-cancel',
		} );
		expect( result.cleanedTokens[ 'legacy-pending-cancel' ] ).toMatchObject(
			{
				type: 'pending',
				pending_token: 'pending-valid',
			}
		);
		expect( result.cleanedTokens ).not.toHaveProperty(
			'orphan-pending-cancel'
		);
		expect( result.clearedTokens ).toEqual(
			expect.arrayContaining( [
				'pending-expired',
				'pending-invalid-meta',
			] )
		);
	} );

	it( 'creates, validates, and consumes linked pending tokens', () => {
		const result = getTokenManagerResult();

		expect( result.createdPendingToken ).toBe( 'uuid-1' );
		expect( result.scheduledExpiry ).toMatchObject( {
			hook: 'rrze_appointment_expire_pending',
			arguments: [ 'uuid-1' ],
		} );
		expect( result.pendingCancellation ).toMatchObject( {
			type: 'pending',
			pending_token: 'uuid-1',
			slot: '2099-01-04 10:00-10:30',
		} );
		expect( result.confirmedPending ).toMatchObject( {
			slot: '2099-01-04 10:00-10:30',
			meta: { title: 'Created' },
		} );
		expect( result.pendingRemoved ).toBe( true );
		expect( result.pendingCancellationRemoved ).toBe( true );
	} );

	it( 'supports legacy booked tokens and rejects malformed token entries', () => {
		const result = getTokenManagerResult();

		expect( result.legacyCancellation ).toEqual( {
			slot: '2099-01-03 10:00-10:30',
			type: 'booked',
		} );
		expect( result.malformedCancellation ).toBeNull();
		expect( result.emptyCancellation ).toBeNull();
	} );

	it( 'stores stable action tokens and builds local URLs', () => {
		const result = getTokenManagerResult();

		expect( result.cancelUrl ).toMatch(
			/^https:\/\/example\.test\/\?rrze_appt_cancel=[a-f0-9]{64}$/
		);
		expect( result.waitlistUrl ).toMatch(
			/^https:\/\/example\.test\/\?rrze_appt_waitlist_optout=[a-f0-9]{64}$/
		);
		expect( result.waitlistSlot ).toBe( '2099-01-03 10:00-10:30' );
		expect( result.unknownWaitlistSlot ).toBeNull();
		expect( result.actionUrls ).toEqual( [
			'https://example.test/?rrze_appt_confirm=confirm-token',
			'https://example.test/?rrze_appt_cancel=cancel-token',
			'https://example.test/?rrze_appt_waitlist_optout=waitlist-token',
		] );
		expect( result.imprintUrl ).toBe( 'https://example.test/impressum/' );
	} );

	it( 'ignores corrupt option shapes and wraps storage failures', () => {
		const result = getTokenManagerResult();

		expect( result.corruptOptionsIgnored ).toEqual( [] );
		expect( result.wrappedFailure ).toEqual( {
			className: 'RRZE\\Appointment\\Common\\CustomException',
			message: 'storage failed',
		} );
	} );
} );
