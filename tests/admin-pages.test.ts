import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type AdminPagesResult = {
	hooks: string[];
	mailTemplateMethods: string[];
	settingsScreen: string;
	bookingsScreen: string;
};

describe( 'focused administration pages', () => {
	it( 'registers their hooks and exposes the extracted template renderer', () => {
		const paths = [
			'includes/Admin/MailTemplatesPage.php',
			'includes/Admin/SettingsPage.php',
			'includes/Admin/BookingsPage.php',
		].map( ( path ) => resolve( process.cwd(), path ) );
		const php = `
			namespace RRZE\\Appointment {
				function plugin() {}
			}
			namespace {
				define( 'ABSPATH', __DIR__ );
				$GLOBALS['hooks'] = [];
				function add_action( $hook, $callback ) { $GLOBALS['hooks'][] = $hook; }

				require ${ JSON.stringify( paths[ 0 ] ) };
				require ${ JSON.stringify( paths[ 1 ] ) };
				require ${ JSON.stringify( paths[ 2 ] ) };

				$mailTemplates = new \\RRZE\\Appointment\\Admin\\MailTemplatesPage();
				( new \\RRZE\\Appointment\\Admin\\SettingsPage( $mailTemplates ) )->register();
				( new \\RRZE\\Appointment\\Admin\\BookingsPage() )->register();

				echo json_encode( [
					'hooks' => $GLOBALS['hooks'],
					'mailTemplateMethods' => get_class_methods( $mailTemplates ),
					'settingsScreen' => \\RRZE\\Appointment\\Admin\\SettingsPage::screenHook(),
					'bookingsScreen' => \\RRZE\\Appointment\\Admin\\BookingsPage::screenHook(),
				] );
			}
		`;

		const result = JSON.parse(
			execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
		) as AdminPagesResult;

		expect( result.hooks ).toEqual( [
			'admin_menu',
			'admin_init',
			'admin_enqueue_scripts',
			'admin_init',
			'admin_init',
			'admin_print_footer_scripts',
			'admin_menu',
			'admin_init',
		] );
		expect( result.mailTemplateMethods ).toContain( 'render' );
		expect( result.settingsScreen ).toBe(
			'settings_page_rrze-appointment-settings'
		);
		expect( result.bookingsScreen ).toBe(
			'toplevel_page_rrze-appointment-bookings'
		);
	} );
} );
