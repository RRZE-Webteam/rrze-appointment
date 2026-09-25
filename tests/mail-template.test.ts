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
	fallbackLayout: string;
};

const getMailTemplateResult = (
	logo: false | [ string, number, number ] = false
): MailTemplateResult => {
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
			$GLOBALS['test_logo'] = json_decode( '${ JSON.stringify( logo ) }', true );
			function has_custom_logo() { return $GLOBALS['test_logo'] !== false; }
			function get_theme_mod( $key ) { return 0; }
			function wp_get_attachment_image_src( $id, $size ) { return $GLOBALS['test_logo']; }
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
				'fallbackLayout' => $reflection->getMethod( 'getFallbackLayout' )->invoke( null ),
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

	it( 'keeps structural padding and colored surfaces on table cells', () => {
		const doc = new DOMParser().parseFromString(
			getMailTemplateResult().wrapped,
			'text/html'
		);

		for ( const name of [ 'header', 'heading', 'content', 'footer' ] ) {
			const cell = doc.querySelector< HTMLTableCellElement >(
				`.rrze-email-${ name }`
			)!;
			expect( cell.tagName ).toBe( 'TD' );
			expect( cell.style.paddingLeft ).not.toBe( '' );
			expect( cell.style.backgroundColor ).not.toBe( '' );
			expect( cell.getAttribute( 'bgcolor' ) ).toMatch(
				/^#[a-f\d]{6}$/i
			);
			expect( cell.closest( 'table' )?.getAttribute( 'role' ) ).toBe(
				'presentation'
			);
		}

		const status = doc.querySelector< HTMLTableCellElement >(
			'td[bgcolor="#e8f5e9"]'
		)!;
		expect( status.textContent ).toContain( 'Booking confirmed' );
		expect( status.style.paddingLeft ).not.toBe( '' );

		const shell =
			doc.querySelector< HTMLTableCellElement >( '.rrze-email-shell' )!;
		expect( shell.tagName ).toBe( 'TD' );
		expect( shell.style.width ).toBe( '' );
		expect( shell.closest( 'table' )?.getAttribute( 'width' ) ).toBe(
			'100%'
		);
	} );

	it( 'preserves the Outlook-only rules needed by runtime buttons after compilation', () => {
		const result = getMailTemplateResult();
		for ( const html of [ result.wrapped, result.fallbackLayout ] ) {
			const outlookRules = [
				...html.matchAll( /<!--\[if mso\]>([\s\S]*?)<!\[endif\]-->/g ),
			]
				.map( ( match ) => match[ 1 ] )
				.join( '\n' );
			expect( outlookRules ).toMatch(
				/\.rrze-email-button-cell\s*\{\s*padding:\s*12px 20px\s*!important/
			);
			expect( outlookRules ).toMatch(
				/\.rrze-email-button-link\s*\{\s*padding:\s*0\s*!important/
			);
		}
	} );

	it( 'spaces runtime tables using presentation cells and preserves data semantics', () => {
		const result = getMailTemplateResult();
		const doc = new DOMParser().parseFromString(
			result.detailsTable + result.placeholderButton,
			'text/html'
		);
		for ( const table of doc.querySelectorAll( 'table' ) ) {
			expect( table.style.margin ).toBe( '' );
		}
		const details = doc.querySelector( '.rrze-email-details' )!;
		expect( details.getAttribute( 'role' ) ).toBeNull();
		expect( details.querySelector( 'th' )?.getAttribute( 'scope' ) ).toBe(
			'row'
		);
		expect( ( details.parentElement as HTMLElement ).style.padding ).toBe(
			'20px 0px'
		);
		const button = doc.querySelector< HTMLAnchorElement >(
			'.rrze-email-button-link'
		)!;
		expect( button.getAttribute( 'href' ) ).toBe( '[confirmation_link]' );
		expect( button.style.padding ).toBe( '12px 20px' );
		expect( button.parentElement?.className ).toBe(
			'rrze-email-button-cell'
		);
	} );

	it.each( [
		[ 1000, 200, 200, 40 ],
		[ 200, 1000, 13, 64 ],
		[ 300, 300, 64, 64 ],
		[ 40, 20, 40, 20 ],
	] )(
		'bounds a %i × %i logo without stretching or upscaling it',
		( width, height, expectedWidth, expectedHeight ) => {
			const result = getMailTemplateResult( [
				'https://example.test/logo.png',
				width,
				height,
			] );
			const doc = new DOMParser().parseFromString(
				result.wrapped,
				'text/html'
			);
			const logo = doc.querySelector( 'img' )!;
			expect( logo.getAttribute( 'width' ) ).toBe(
				String( expectedWidth )
			);
			expect( logo.getAttribute( 'height' ) ).toBe(
				String( expectedHeight )
			);
			expect( logo.getAttribute( 'alt' ) ).toBe( '<Example & Site>' );
		}
	);

	it( 'falls back to the site name if logo dimensions are unavailable', () => {
		const result = getMailTemplateResult( [
			'https://example.test/logo.png',
			0,
			0,
		] );
		const doc = new DOMParser().parseFromString(
			result.wrapped,
			'text/html'
		);
		expect( doc.querySelector( 'img' ) ).toBeNull();
		expect(
			doc.querySelector( '.rrze-email-header' )?.textContent
		).toContain( '<Example & Site>' );
	} );
} );
