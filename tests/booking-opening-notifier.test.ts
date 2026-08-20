import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type NotifierResult = {
	first: { token: string; statusToken: string; created: boolean };
	second: { token: string; statusToken: string; created: boolean };
	entryCount: number;
	statusSlot: string;
	registrationUrl: string;
	scheduledCount: number;
	entryCountAfterCleanup: number;
	clearedTokens: string[];
};

const runNotifierScenario = (): NotifierResult => {
	const notifierPath = resolve(
		process.cwd(),
		'includes/BookingOpeningNotifier.php'
	);
	const php = `
		namespace RRZE\\Appointment\\Common {
			class CustomException extends \\Exception {}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			define( 'MINUTE_IN_SECONDS', 60 );
			$GLOBALS['options'] = [];
			$GLOBALS['scheduled'] = [];
			$GLOBALS['cleared'] = [];
			$GLOBALS['uuid_counter'] = 0;
			function get_option( $key, $default = false ) {
				return $GLOBALS['options'][ $key ] ?? $default;
			}
			function update_option( $key, $value, $autoload = null ) {
				$GLOBALS['options'][ $key ] = $value;
				return true;
			}
			function sanitize_email( $email ) {
				return trim( (string) $email );
			}
			function wp_generate_uuid4() {
				++$GLOBALS['uuid_counter'];
				return 'uuid-' . $GLOBALS['uuid_counter'];
			}
			function wp_next_scheduled( $hook, $args ) {
				$key = $hook . ':' . $args[0];
				return $GLOBALS['scheduled'][ $key ] ?? false;
			}
			function wp_schedule_single_event( $timestamp, $hook, $args ) {
				$GLOBALS['scheduled'][ $hook . ':' . $args[0] ] = $timestamp;
				return true;
			}
			function wp_clear_scheduled_hook( $hook, $args ) {
				$GLOBALS['cleared'][] = (string) $args[0];
				unset( $GLOBALS['scheduled'][ $hook . ':' . $args[0] ] );
			}
			function home_url( $path ) {
				return 'https://example.test' . $path;
			}
			function add_query_arg( $key, $value, $url ) {
				return $url . '?' . $key . '=' . rawurlencode( $value );
			}
			require ${ JSON.stringify( notifierPath ) };
			$notifier = \\RRZE\\Appointment\\BookingOpeningNotifier::class;
			$now = time();
			$meta = [ 'booker_email' => 'Person@Example.test', 'title' => 'Consultation' ];
			$first = $notifier::subscribe(
				'2099-01-01 10:00-10:30',
				$meta,
				$now + 3600,
				$now + 7200
			);
			$second = $notifier::subscribe(
				'2099-01-01 10:00-10:30',
				$meta,
				$now + 3600,
				$now + 7200
			);
			$entries = get_option( $notifier::OPTION, [] );
			$status = $notifier::getSubscriptionByStatusToken( $first['statusToken'] );
			$entries['expired-token'] = [
				'slot' => '2000-01-01 10:00-10:30',
				'closes_at' => $now - 1,
			];
			update_option( $notifier::OPTION, $entries, false );
			$notifier::cleanup();
			echo json_encode( [
				'first' => $first,
				'second' => $second,
				'entryCount' => count( $entries ) - 1,
				'statusSlot' => $status['slot'] ?? '',
				'registrationUrl' => $notifier::registrationUrl( $first['statusToken'] ),
				'scheduledCount' => count( $GLOBALS['scheduled'] ),
				'entryCountAfterCleanup' => count( get_option( $notifier::OPTION, [] ) ),
				'clearedTokens' => $GLOBALS['cleared'],
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as NotifierResult;
};

describe( 'booking opening notifier', () => {
	it( 'deduplicates subscriptions and cleans expired entries', () => {
		const result = runNotifierScenario();

		expect( result.first ).toEqual( {
			token: 'uuid-1',
			statusToken: 'uuid-2',
			created: true,
		} );
		expect( result.second ).toEqual( {
			token: result.first.token,
			statusToken: result.first.statusToken,
			created: false,
		} );
		expect( result.entryCount ).toBe( 1 );
		expect( result.statusSlot ).toBe( '2099-01-01 10:00-10:30' );
		expect( result.registrationUrl ).toBe(
			'https://example.test/?rrze_appt_opening_registered=uuid-2'
		);
		expect( result.scheduledCount ).toBe( 1 );
		expect( result.entryCountAfterCleanup ).toBe( 1 );
		expect( result.clearedTokens ).toContain( 'expired-token' );
	} );
} );
