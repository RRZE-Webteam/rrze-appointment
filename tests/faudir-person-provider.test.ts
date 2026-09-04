import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type Person = {
	id: number;
	label: string;
	honorificPrefix: string;
	givenName: string;
	familyName: string;
	email: string;
	location: string;
	locationUrl: string;
	consultationHours: Array< {
		weekday: number;
		from: string;
		to: string;
	} >;
	hoursType: 'consultation' | 'office' | null;
};

type ProviderResult = {
	missingDependency: { error: boolean; message: string; data: Person[] };
	available: { error: boolean; message: string; data: Person[] };
};

const getProviderResult = (): ProviderResult => {
	const providerPath = resolve(
		process.cwd(),
		'includes/FaudirPersonProvider.php'
	);
	const php = `
		namespace RRZE\\FAUdir {
			class Config {}
			class API {
				public function __construct( Config $config ) {}
				public function getPerson( string $id ): ?array {
					$people = [
						'person-12' => [
							'givenName' => '<b>Ada</b>',
							'familyName' => 'Lovelace',
							'honorificPrefix' => '<i>Dr.</i>',
							'email' => 'invalid',
							'contacts' => [ [ 'identifier' => 'contact-1' ], 'malformed' ],
						],
						'person-14' => [
							'contacts' => 'malformed',
							'workplaces' => [
								[ 'nested' => [ 'mail' => 'fallback@example.test' ] ],
							],
						],
					];
					return $people[ $id ] ?? null;
				}
				public function getContact( string $id ): ?array {
					if ( $id !== 'contact-1' ) {
						return null;
					}
					return [
						'workplaces' => [
							[
								'emails' => [ [ 'value' => 'ada@example.test' ] ],
								'consultationHours' => [
									[ 'weekday' => 1, 'from' => '<b>09:00</b>', 'to' => '11:00', 'comment' => 'discarded' ],
									[ 'weekday' => 9, 'from' => '12:00', 'to' => '13:00' ],
									'malformed',
								],
								'room' => '<b>1.23</b>',
								'street' => 'Martensstraße 1',
								'city' => 'Erlangen',
								'faumap' => 'https://karte.fau.de/place/1',
							],
						],
					];
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['post_type_available'] = false;
			function __( $message, $domain ) { return $message; }
			function rest_ensure_response( $value ) { return $value; }
			function post_type_exists( $postType ) { return $GLOBALS['post_type_available']; }
			function get_posts( $arguments ) { return [ '12', 13, 14, 15 ]; }
			function get_post_meta( $postId, $key, $single ) {
				return [ 12 => ' person-12 ', 13 => 'person-13', 14 => 'person-14', 15 => '' ][ $postId ];
			}
			function get_the_title( $postId ) { return $postId === 14 ? '<b>Fallback Host</b>' : ''; }
			function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
			function sanitize_email( $value ) {
				return filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : '';
			}
			function esc_url_raw( $value ) {
				return preg_match( '#^https?://#', $value ) ? $value : '';
			}
			require ${ JSON.stringify( providerPath ) };
			$provider = new \\RRZE\\Appointment\\FaudirPersonProvider();
			$missingDependency = $provider->handleRequest();
			$GLOBALS['post_type_available'] = true;
			$available = $provider->handleRequest();
			echo json_encode( compact( 'missingDependency', 'available' ) );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as ProviderResult;
};

describe( 'FAUdir person provider', () => {
	it( 'returns the editor response schema when FAUdir is unavailable', () => {
		const result = getProviderResult();

		expect( result.missingDependency ).toEqual( {
			error: true,
			message:
				'Tip: Activate the RRZE FAUdir plugin to conveniently import person data.',
			data: [],
		} );
	} );

	it( 'normalizes external people, contact details, and appointment hours', () => {
		const result = getProviderResult();

		expect( result.available.error ).toBe( false );
		expect( result.available.data ).toHaveLength( 2 );
		expect( result.available.data[ 0 ] ).toMatchObject( {
			id: 12,
			label: 'Ada Lovelace',
			honorificPrefix: 'Dr.',
			givenName: 'Ada',
			familyName: 'Lovelace',
			email: 'ada@example.test',
			location: '1.23, Martensstraße 1, Erlangen',
			locationUrl: 'https://karte.fau.de/place/1',
			hoursType: 'consultation',
			consultationHours: [ { weekday: 1, from: '09:00', to: '11:00' } ],
		} );
	} );

	it( 'falls back to post titles and nested workplace emails safely', () => {
		const result = getProviderResult();
		const fallbackPerson = result.available.data[ 1 ];

		expect( fallbackPerson ).toMatchObject( {
			id: 14,
			label: 'Fallback Host',
			email: 'fallback@example.test',
			location: '',
			locationUrl: '',
			consultationHours: [],
			hoursType: null,
		} );
	} );
} );
