import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
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
				/** Records filters installed before the Settings API processes a submission. */
				function add_filter( $hook, $callback ) { $GLOBALS['hooks'][] = $hook; }

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
			'allowed_options',
			'option_page_capability_rrze_appointment_settings_general_v2',
			'option_page_capability_rrze_appointment_settings_people_v2',
			'check_admin_referer',
			'admin_enqueue_scripts',
			'admin_init',
			'admin_init',
			'admin_print_footer_scripts',
			'admin_menu',
			'admin_enqueue_scripts',
			'load-toplevel_page_rrze-appointment-bookings',
		] );
		expect( result.mailTemplateMethods ).toContain( 'render' );
		expect( result.settingsScreen ).toBe(
			'settings_page_rrze-appointment-settings'
		);
		expect( result.bookingsScreen ).toBe(
			'toplevel_page_rrze-appointment-bookings'
		);
	} );

	it( 'stops outdated general forms after WordPress validates the settings nonce', () => {
		const php = `
			use RRZE\\Appointment\\Admin\\MailTemplatesPage;
			use RRZE\\Appointment\\Admin\\SettingsPage;
			use RRZE\\Appointment\\Configuration\\PluginSettings;
			define('ABSPATH', __DIR__);
			require 'includes/Configuration/PluginSettings.php';
			require 'includes/Admin/MailTemplatesPage.php';
			require 'includes/Admin/SettingsPage.php';

			/** Passes through translated fixture strings. */
			function esc_html__($text, $domain) { return $text; }
			/** Returns an admin-relative URL for the reload link. */
			function admin_url($path) { return '/wp-admin/' . $path; }
			/** Appends the plugin page to its settings URL. */
			function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
			/** Captures a WordPress error response before the option can be saved. */
			function wp_die($message, $title, $args) {
				throw new RuntimeException(json_encode([$message, $args['link_url']]), $args['response']);
			}

			$page = new SettingsPage(new MailTemplatesPage());
			$action = 'rrze_appointment_settings_group-options';
			foreach ([
				'oldWithPeople' => ['general', true],
				'oldWithoutPeople' => ['general', false],
				'noScope' => [null, false],
				'currentGeneral' => [PluginSettings::GENERAL_SCOPE, false],
				'illustrations' => ['illustrations', false],
				'additionalPeople' => ['additional_people', false],
				'versionedGeneral' => [PluginSettings::GENERAL_SCOPE, false],
				'versionedPeople' => ['additional_people', false],
				'wrongGeneralScope' => ['additional_people', false],
				'wrongPeopleScope' => [PluginSettings::GENERAL_SCOPE, false],
				'missingVersionedScope' => [null, false],
				'otherGroup' => ['general', false],
				'otherScreen' => ['general', false],
				'invalidNonce' => ['general', false],
			] as $scenario => [$scope, $hasPeople]) {
				$pagenow = $scenario === 'otherScreen' ? 'admin.php' : 'options.php';
				$_POST = ['action' => 'update', PluginSettings::OPTION_NAME => ['retention_days' => '90']];
				if ($scope !== null) { $_POST[PluginSettings::OPTION_NAME]['_settings_scope'] = $scope; }
				if ($hasPeople) { $_POST[PluginSettings::OPTION_NAME]['appointment_manager_user_ids'] = [7]; }
				$nonceAction = match ($scenario) {
					'versionedGeneral', 'wrongGeneralScope', 'missingVersionedScope' => 'rrze_appointment_settings_general_v2-options',
					'versionedPeople', 'wrongPeopleScope' => 'rrze_appointment_settings_people_v2-options',
					'otherGroup' => 'other-options',
					default => $action,
				};
				try {
					$page->rejectOutdatedSettingsForm($nonceAction, $scenario === 'invalidNonce' ? false : 1);
					$results[$scenario] = null;
				} catch (RuntimeException $error) {
					$results[$scenario] = [$error->getCode(), ...json_decode($error->getMessage(), true)];
				}
			}
			echo json_encode($results);
		`;
		const blocked = [
			409,
			'These settings have been updated. Reload the page and make your change again.',
			'/wp-admin/options-general.php?page=rrze-appointment-settings',
		];
		expect(
			JSON.parse(
				execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
			)
		).toEqual( {
			oldWithPeople: blocked,
			oldWithoutPeople: blocked,
			noScope: blocked,
			currentGeneral: null,
			illustrations: null,
			additionalPeople: null,
			versionedGeneral: null,
			versionedPeople: null,
			wrongGeneralScope: blocked,
			wrongPeopleScope: blocked,
			missingVersionedScope: blocked,
			otherGroup: null,
			otherScreen: null,
			invalidNonce: null,
		} );
	} );

	it( 'preserves the registered settings groups and limits both people forms to the plugin option', () => {
		const php = `
			use RRZE\\Appointment\\Admin\\MailTemplatesPage;
			use RRZE\\Appointment\\Admin\\SettingsPage;
			use RRZE\\Appointment\\Configuration\\PluginSettings;
			define('ABSPATH', __DIR__);
			require 'includes/Configuration/PluginSettings.php';
			require 'includes/Admin/MailTemplatesPage.php';
			require 'includes/Admin/SettingsPage.php';
			/** Models the capability override on the original group. */
			function apply_filters($name, $original) {
				return $name === 'option_page_capability_rrze_appointment_settings_group' ? 'site_settings_cap' : $original;
			}
			/** Records the options that WordPress registers for update_option(). */
			function register_setting($group, $option, $arguments) { $GLOBALS['registeredOptions'][$group][] = $option; }
			/** Records which sections allow extensions to display their existing fields. */
			function add_settings_section($id, $title, $callback, $page) { $GLOBALS['sections'][$page][] = $id; }
			/** Leaves the built-in fields out of the extension registration check. */
			function add_settings_field(...$arguments) {}
			/** Returns the original settings labels. */
			function __($label, $domain) { return $label; }
			$page = new SettingsPage(new MailTemplatesPage());
			$page->registerSettings();
			$group = 'rrze_appointment_settings_group';
			$option = PluginSettings::OPTION_NAME;
			$registered = $GLOBALS['registeredOptions'];
			$registered[$group][] = 'general_extension';
			$registered[$group][] = 'illustration_extension';
			$versioned = $page->allowFormOptions($registered);
			// An unchecked extension checkbox is absent from the request and must still reach its sanitizer.
			$_POST = ['option_page' => $group, $option => ['_settings_scope' => 'illustrations']];
			$_REQUEST['option_page'] = $group;
			$illustrations = $page->allowFormOptions($registered)[$group];
			$_POST[$option]['_settings_scope'] = 'additional_people';
			$people = $page->allowFormOptions($registered)[$group];
			echo json_encode([
				'general' => $versioned['rrze_appointment_settings_general_v2'],
				'people' => $versioned['rrze_appointment_settings_people_v2'],
				'illustrations' => $illustrations, 'legacyPeople' => $people,
				'capability' => $page->formCapability('manage_options'),
				'legacySection' => in_array('rrze_appointment_general', $GLOBALS['sections'][SettingsPage::PAGE_SLUG], true),
				'unregistered' => $page->allowFormOptions([]),
			]);
		`;
		const option = 'rrze_appointment_settings';
		expect(
			JSON.parse(
				execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
			)
		).toEqual( {
			general: [ option, 'general_extension', 'illustration_extension' ],
			people: [ option ],
			illustrations: [
				option,
				'general_extension',
				'illustration_extension',
			],
			legacyPeople: [ option ],
			capability: 'site_settings_cap',
			legacySection: true,
			unregistered: [],
		} );
	} );

	it( 'mounts the React administration screen and loads its own bundle', () => {
		const page = readFileSync(
			resolve( process.cwd(), 'includes/Admin/BookingsPage.php' ),
			'utf8'
		);
		expect( page ).toContain( 'id="rrze-appointment-admin"' );
		expect( page ).toContain( 'build/admin-bookings.js' );
		expect( page ).toContain( 'wp_set_script_translations(' );
		expect( page ).not.toContain( 'private function renderBookings' );
	} );

	it( 'registers a switch for the cancellation-reason field', () => {
		const settings = readFileSync(
			resolve( process.cwd(), 'includes/Admin/SettingsPage.php' ),
			'utf8'
		);

		expect( settings ).toContain( "'cancellation_reason_enabled'" );
		expect( settings ).toContain( 'renderCancellationReasonField' );
	} );

	it( 'registers the sensitive appointment mode for the admin overview', () => {
		const settings = readFileSync(
			resolve( process.cwd(), 'includes/Admin/SettingsPage.php' ),
			'utf8'
		);
		const bookings = readFileSync(
			resolve( process.cwd(), 'includes/Booking/Bookings.php' ),
			'utf8'
		);

		expect( settings ).toContain( "'sensitive_mode_enabled'" );
		expect( settings ).toContain( 'renderSensitiveModeField' );
		expect( bookings ).toContain(
			"PluginSettings::get('sensitive_mode_enabled')"
		);
		expect( bookings ).toContain(
			'!empty($meta[self::ADMIN_ANONYMIZED_META_KEY])'
		);
	} );

	it( 'delegates appointment management through a server-validated user list', () => {
		const settings = readFileSync(
			resolve( process.cwd(), 'includes/Admin/SettingsPage.php' ),
			'utf8'
		);
		const bookings = readFileSync(
			resolve( process.cwd(), 'includes/Admin/BookingsPage.php' ),
			'utf8'
		);

		expect( settings ).toContain( "'appointment_manager_user_ids'" );
		expect( settings ).toContain( 'renderAppointmentManagersField' );
		expect( settings ).toContain( 'value="additional_people"' );
		expect( bookings ).toContain(
			'AppointmentPermissions::currentUserCanManage()'
		);
		expect( bookings ).toContain( "'read'," );
		expect( bookings ).not.toContain(
			"|| !current_user_can('manage_options')"
		);
	} );

	it( 'provides media-library controls for public illustrations', () => {
		const settings = readFileSync(
			resolve( process.cwd(), 'includes/Admin/SettingsPage.php' ),
			'utf8'
		);
		const script = readFileSync(
			resolve( process.cwd(), 'assets/js/rrze-appointment-admin.js' ),
			'utf8'
		);

		expect( settings ).toContain( 'renderIllustrationsField' );
		expect( settings ).toContain(
			"'illustrations' => __('Illustrations', 'rrze-appointment')"
		);
		expect( settings ).toContain(
			'do_settings_sections(self::ILLUSTRATIONS_PAGE);'
		);
		expect( settings ).toContain( 'value="illustrations"' );
		expect( settings ).toContain( 'wp_enqueue_media();' );
		expect( settings ).toContain( '[illustrations][' );
		expect( script ).toContain( "library: { type: 'image' }" );
		expect( script ).toContain( "input.value = '0';" );
	} );
} );
