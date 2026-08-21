import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type AssetResult = {
	handle: string;
	objectName?: string;
	position?: string;
	configuration?: Record< string, unknown >;
};

const runAssetManager = (
	method: 'enqueueFrontendAssets' | 'enqueueEditorAssets',
	settingsFail = false
): AssetResult | null => {
	const assetManagerPath = resolve(
		process.cwd(),
		'includes/Presentation/AssetManager.php'
	);
	const php = `
		namespace RRZE\\Appointment\\Common {
			class CustomException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Booking {
			class Bookings {
				public const SLOTS_OPTION = 'rrze_appointment_booked_slots';
			}
			class TokenManager {
				public static function getPendingSlots() {
					return [ '2099-01-01 10:00-10:30', '2099-01-02 11:00-11:30' ];
				}
			}
		}
		namespace RRZE\\Appointment {
			class Settings {
				public static function get( $key ) {
					if ( ${ settingsFail ? 'true' : 'false' } ) {
						throw new \\RRZE\\Appointment\\Common\\CustomException( 'Unavailable' );
					}
					return 24;
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['asset_result'] = null;
			function wp_script_is( $handle, $status ) {
				return true;
			}
			function get_option( $key, $default = [] ) {
				return [ '2099-01-01 10:00-10:30', '2099-01-01 10:00-10:30' ];
			}
			function admin_url( $path ) {
				return 'https://example.test/wp-admin/' . $path;
			}
			function rest_url( $path ) {
				return 'https://example.test/wp-json/' . $path;
			}
			function wp_create_nonce( $action ) {
				return 'nonce-' . $action;
			}
			function determine_locale() {
				return 'de_DE';
			}
			function post_type_exists( $postType ) {
				return true;
			}
			function __( $text, $domain ) {
				return $text;
			}
			function wp_json_encode( $value ) {
				return json_encode( $value );
			}
			function wp_localize_script( $handle, $objectName, $configuration ) {
				$GLOBALS['asset_result'] = compact( 'handle', 'objectName', 'configuration' );
			}
			function wp_add_inline_script( $handle, $data, $position ) {
				preg_match( '/ = (.*);$/', $data, $matches );
				$configuration = json_decode( $matches[1], true );
				$GLOBALS['asset_result'] = compact( 'handle', 'position', 'configuration' );
			}
			require ${ JSON.stringify( assetManagerPath ) };
			$manager = new \\RRZE\\Appointment\\Presentation\\AssetManager();
			$manager->${ method }();
			echo json_encode( $GLOBALS['asset_result'] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as AssetResult | null;
};

describe( 'asset manager', () => {
	it( 'localizes unique booked and pending slots for the public script', () => {
		const result = runAssetManager( 'enqueueFrontendAssets' );

		expect( result ).toMatchObject( {
			handle: 'rrze-appointment-view-script',
			objectName: 'rrze_appointment',
			configuration: {
				locale: 'de-DE',
				bookedSlots: [
					'2099-01-01 10:00-10:30',
					'2099-01-02 11:00-11:30',
				],
			},
		} );
	} );

	it( 'uses documented defaults when editor configuration fails', () => {
		const result = runAssetManager( 'enqueueEditorAssets', true );

		expect( result ).toMatchObject( {
			handle: 'rrze-appointment-editor-script',
			position: 'before',
			configuration: {
				faudir: {
					available: false,
					personsPath: '/rrze/v2/appointment/persons',
				},
				recurrenceLimit: 52,
			},
		} );
	} );
} );
