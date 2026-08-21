import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type Action = {
	hook: string;
	callback: string;
	priority: number;
	acceptedArgs: number;
};

type MainResult = {
	actions: Action[];
	routes: Array< {
		namespace: string;
		route: string;
		methods: string;
		callback: string;
		permissionCallback: string;
	} >;
	maintenance: Record< string, number >;
	bookerAllowed: boolean;
	personsDenied: boolean;
	personsAllowed: boolean;
	legacySettingsConstructed: number;
};

const getMainResult = (): MainResult => {
	const mainPath = resolve( process.cwd(), 'includes/Main.php' );
	const php = `
		namespace RRZE\\Appointment\\Common\\Settings {
			class Settings {
				public static int $constructed = 0;
				public function __construct( string $title ) { self::$constructed++; }
				public function setCapability( string $value ): self { return $this; }
				public function setOptionName( string $value ): self { return $this; }
				public function setMenuTitle( string $value ): self { return $this; }
				public function setMenuPosition( int $value ): self { return $this; }
				public function setMenuParentSlug( string $value ): self { return $this; }
				public function build(): void {}
			}
		}
		namespace RRZE\\Appointment\\Presentation {
			class PublicPageRenderer {}
			class AssetManager {
				public function enqueueFrontendAssets(): void {}
				public function enqueueEditorAssets(): void {}
			}
		}
		namespace RRZE\\Appointment {
			class FaudirPersonProvider { public function handleRequest(): void {} }
			class Defaults { public function get( string $key ) { return null; } }
			class Settings {
				public static int $registered = 0;
				public function register(): void { self::$registered++; }
			}
		}
		namespace RRZE\\Appointment\\Mail {
			class MailTemplatePost {
				public static int $initialized = 0;
				public static function register(): void {}
				public static function ensureEditableDefaultTemplateExists(): void { self::$initialized++; }
			}
		}
		namespace RRZE\\Appointment\\Booking {
			class TokenManager {
				public const PENDING_EXPIRY_HOOK = 'pending_expiry';
				public static int $cleaned = 0;
				public static function cleanupPendingState(): void { self::$cleaned++; }
				public static function expirePending(): void {}
			}
		}
		namespace RRZE\\Appointment\\Controller {
			class BookingOpeningController {
				public function __construct( \\RRZE\\Appointment\\Presentation\\PublicPageRenderer $renderer ) {}
				public function handleSubscription(): void {}
				public function handleClaim(): void {}
				public function handleRegistrationStatus(): void {}
			}
			class BookingRequestController { public function handleRequest(): void {} }
			class CancellationController {
				public function __construct( \\RRZE\\Appointment\\Presentation\\PublicPageRenderer $renderer ) {}
				public function handleCancellation(): void {}
				public function handleWaitlistPreference(): void {}
			}
			class ConfirmationController {
				public function __construct( \\RRZE\\Appointment\\Presentation\\PublicPageRenderer $renderer ) {}
				public function handleConfirmation(): void {}
			}
			class SsoController {
				public function handleBookerRequest(): void {}
				public function handleLogin(): void {}
			}
		}
		namespace RRZE\\Appointment\\Notification {
			class WaitlistNotifier { public function handlePostUpdated(): void {} }
			class Reminder {
				public static int $registered = 0;
				public function register(): void { self::$registered++; }
			}
			class BookingOpeningNotifier {
				public const CRON_HOOK = 'opening_cron';
				public static int $cleaned = 0;
				public static function cleanup(): void { self::$cleaned++; }
				public static function notify(): void {}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['actions'] = [];
			$GLOBALS['routes'] = [];
			$GLOBALS['can_edit_posts'] = false;
			function describe_callback( $callback ): string {
				if ( ! is_array( $callback ) ) { return (string) $callback; }
				$owner = is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0];
				return $owner . '::' . $callback[1];
			}
			function add_action( $hook, $callback, $priority = 10, $acceptedArgs = 1 ) {
				$GLOBALS['actions'][] = [
					'hook' => $hook,
					'callback' => describe_callback( $callback ),
					'priority' => $priority,
					'acceptedArgs' => $acceptedArgs,
				];
			}
			function register_rest_route( $namespace, $route, $arguments ) {
				$GLOBALS['routes'][] = [
					'namespace' => $namespace,
					'route' => $route,
					'methods' => $arguments['methods'],
					'callback' => describe_callback( $arguments['callback'] ),
					'permissionCallback' => describe_callback( $arguments['permission_callback'] ),
				];
			}
			function current_user_can( $capability ) { return $GLOBALS['can_edit_posts']; }
			function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $value ) ); }
			function __( $value, $domain ) { return $value; }
			require ${ JSON.stringify( mainPath ) };

			$main = new \\RRZE\\Appointment\\Main();
			$main->onInit();
			$main->registerRestRoutes();
			$bookerAllowed = $main->allowBookerRequest( null );
			$personsDenied = ! $main->allowPersonsRequest( null );
			$GLOBALS['can_edit_posts'] = true;
			$personsAllowed = $main->allowPersonsRequest( null );
			$main->settings();
			echo json_encode( [
				'actions' => $GLOBALS['actions'],
				'routes' => $GLOBALS['routes'],
				'maintenance' => [
					'defaultTemplate' => \\RRZE\\Appointment\\Mail\\MailTemplatePost::$initialized,
					'pendingState' => \\RRZE\\Appointment\\Booking\\TokenManager::$cleaned,
					'openingState' => \\RRZE\\Appointment\\Notification\\BookingOpeningNotifier::$cleaned,
					'settings' => \\RRZE\\Appointment\\Settings::$registered,
					'reminder' => \\RRZE\\Appointment\\Notification\\Reminder::$registered,
				],
				'bookerAllowed' => $bookerAllowed,
				'personsDenied' => $personsDenied,
				'personsAllowed' => $personsAllowed,
				'legacySettingsConstructed' => \\RRZE\\Appointment\\Common\\Settings\\Settings::$constructed,
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as MainResult;
};

describe( 'plugin bootstrap', () => {
	it( 'registers lifecycle maintenance and focused runtime hooks', () => {
		const result = getMainResult();
		const hookNames = result.actions.map( ( action ) => action.hook );

		expect( result.maintenance ).toEqual( {
			defaultTemplate: 1,
			pendingState: 1,
			openingState: 1,
			settings: 1,
			reminder: 1,
		} );
		expect( hookNames ).toEqual(
			expect.arrayContaining( [
				'init',
				'wp_enqueue_scripts',
				'wp_ajax_rrze_appointment_book',
				'wp_ajax_nopriv_rrze_appointment_book',
				'wp_ajax_rrze_appointment_notify_opening',
				'template_redirect',
				'pending_expiry',
				'opening_cron',
				'post_updated',
				'rest_api_init',
			] )
		);
		const postUpdated = result.actions.find(
			( action ) => action.hook === 'post_updated'
		);
		expect( postUpdated ).toMatchObject( {
			priority: 10,
			acceptedArgs: 3,
		} );
	} );

	it( 'registers public booker and protected person REST routes', () => {
		const result = getMainResult();

		expect( result.routes ).toEqual( [
			{
				namespace: 'rrze/v2/appointment',
				route: '/booker',
				methods: 'POST',
				callback:
					'RRZE\\Appointment\\Controller\\SsoController::handleBookerRequest',
				permissionCallback:
					'RRZE\\Appointment\\Main::allowBookerRequest',
			},
			{
				namespace: 'rrze/v2/appointment',
				route: '/persons',
				methods: 'GET',
				callback:
					'RRZE\\Appointment\\FaudirPersonProvider::handleRequest',
				permissionCallback:
					'RRZE\\Appointment\\Main::allowPersonsRequest',
			},
		] );
		expect( result.bookerAllowed ).toBe( true );
		expect( result.personsDenied ).toBe( true );
		expect( result.personsAllowed ).toBe( true );
	} );

	it( 'ignores incomplete legacy settings defaults safely', () => {
		expect( getMainResult().legacySettingsConstructed ).toBe( 0 );
	} );
} );
