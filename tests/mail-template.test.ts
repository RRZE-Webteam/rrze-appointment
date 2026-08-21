import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type MailTemplateResult = {
	wrapped: string;
	statuses: Record< string, string >;
	detailsTable: string;
	placeholderButton: string;
	safeButton: string;
	unsafeButton: string;
	compiledLayoutValid: boolean;
	incompleteLayoutValid: boolean;
};

const getMailTemplateResult = (): MailTemplateResult => {
	const templatePath = resolve(
		process.cwd(),
		'includes/Mail/MailTemplate.php'
	);
	const php = `
		namespace RRZE\\Appointment\\Booking {
			class TokenManager {
				public static function imprintUrl(): string {
					return 'https://example.test/legal';
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			function get_bloginfo( $key ) {
				return $key === 'name' ? '<Example & Site>' : 'de_DE';
			}
			function home_url( $path = '' ) { return 'https://example.test' . $path; }
			function is_rtl() { return false; }
			function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
			function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
			function esc_html__( $value, $domain ) { return esc_html( $value ); }
			function esc_url( $value ) {
				return preg_match( '#^https?://#', $value ) ? esc_attr( $value ) : '';
			}
			function has_custom_logo() { return false; }
			function get_theme_mod( $key ) { return 0; }
			function wp_get_attachment_image_url( $id, $size ) { return false; }
			function get_privacy_policy_url() { return 'https://example.test/privacy'; }
			function __( $value, $domain ) { return $value; }
			require ${ JSON.stringify( templatePath ) };

			$class = \\RRZE\\Appointment\\Mail\\MailTemplate::class;
			$reflection = new ReflectionClass( $class );
			$validateLayout = $reflection->getMethod( 'hasRequiredLayoutMarkers' );
			$compiledLayout = file_get_contents(
				dirname( ${ JSON.stringify(
					templatePath
				) } ) . '/../../build/email/layout.html'
			);
			$incompleteLayout = str_replace( '___RRZE_EMAIL_LOGO___', '', $compiledLayout );
			echo json_encode( [
				'wrapped' => $class::wrap(
					'<p>Trusted <strong>[name]</strong></p>',
					'<Subject>',
					$class::STATUS_SUCCESS
				),
				'statuses' => [
					'pending' => $class::statusForType( 'booking_pending' ),
					'booked' => $class::statusForType( 'booking_host' ),
					'cancelled' => $class::statusForType( 'cancellation' ),
					'unknown' => $class::statusForType( 'unknown' ),
				],
				'detailsTable' => $class::detailsTable( [
					'<Host>' => '<strong>[person_name]</strong>',
				] ),
				'placeholderButton' => $class::actionButton( '[confirmation_link]', '<Confirm>' ),
				'safeButton' => $class::actionButton( 'https://example.test/book?a=1&b=2', 'Book' ),
				'unsafeButton' => $class::actionButton( 'javascript:alert(1)', 'Unsafe' ),
				'compiledLayoutValid' => $validateLayout->invoke( null, $compiledLayout ),
				'incompleteLayoutValid' => $validateLayout->invoke( null, $incompleteLayout ),
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as MailTemplateResult;
};

describe( 'mail template', () => {
	it( 'wraps trusted content while escaping layout metadata', () => {
		const result = getMailTemplateResult();

		expect( result.wrapped ).toContain(
			'<p>Trusted <strong>[name]</strong></p>'
		);
		expect( result.wrapped ).toContain( '&lt;Subject&gt;' );
		expect( result.wrapped ).toContain( '&lt;Example &amp; Site&gt;' );
		expect( result.wrapped ).toContain( 'Booking confirmed' );
		expect( result.wrapped ).toContain( 'rel="noopener noreferrer"' );
		expect( result.wrapped ).not.toContain( '___RRZE_EMAIL_' );
	} );

	it( 'maps template types to their visual statuses', () => {
		expect( getMailTemplateResult().statuses ).toEqual( {
			pending: 'warning',
			booked: 'success',
			cancelled: 'danger',
			unknown: 'neutral',
		} );
	} );

	it( 'escapes component labels while retaining trusted template values', () => {
		const result = getMailTemplateResult();

		expect( result.detailsTable ).toContain( '&lt;Host&gt;' );
		expect( result.detailsTable ).toContain(
			'<strong>[person_name]</strong>'
		);
		expect( result.placeholderButton ).toContain(
			'href="[confirmation_link]"'
		);
		expect( result.placeholderButton ).toContain( '&lt;Confirm&gt;' );
		expect( result.safeButton ).toContain(
			'href="https://example.test/book?a=1&amp;b=2"'
		);
		expect( result.unsafeButton ).toContain( 'href=""' );
		expect( result.unsafeButton ).not.toContain( 'javascript:' );
	} );

	it( 'accepts only complete compiled layouts', () => {
		const result = getMailTemplateResult();

		expect( result.compiledLayoutValid ).toBe( true );
		expect( result.incompleteLayoutValid ).toBe( false );
	} );
} );
