import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type SettingsResult = {
	registeredHooks: string[];
	defaultReminderDays: number;
	storedReminderDays: number;
	blankRetentionDays: number;
	sanitized: {
		reminder_days: number;
		recurrence_limit: number;
		retention_days: number;
	};
	renderedTemplate: string;
	successfulMail: {
		result: boolean;
		wrappedHtml: string;
		hookRemoved: boolean;
		globalRemoved: boolean;
	};
	failedMail: {
		message: string;
		hookRemoved: boolean;
		globalRemoved: boolean;
	};
	htmlMail: {
		charset: string;
		plainBody: string;
		htmlBody: string;
		isHtml: boolean;
	};
	requestValues: {
		text: string;
		key: string;
		invalidInt: number;
		scalarFlag: boolean;
		arrayFlag: boolean;
	};
	adminHooks: string[];
};

const getSettingsResult = (): SettingsResult => {
	const settingsPath = resolve( process.cwd(), 'includes/Settings.php' );
	const php = `
		namespace PHPMailer\\PHPMailer {
			class PHPMailer {
				public $CharSet = '';
				public $AltBody = '';
				public $Body = 'Plain body';
				public $isHtml = false;
				public function isHTML( $enabled ) { $this->isHtml = $enabled; }
			}
		}
		namespace RRZE\\Appointment\\Mail {
			class MailTemplate {
				public const STATUS_NEUTRAL = 'neutral';
				public static function wrap( $html, $subject, $status ) {
					return $status . ':' . $subject . ':' . $html;
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['options'] = [];
			$GLOBALS['actions'] = [];
			$GLOBALS['removed_actions'] = [];
			$GLOBALS['mail_should_throw'] = false;
			$GLOBALS['mail_html'] = '';

			function __( $value, $domain ) { return $value; }
			function get_option( $key, $default = false ) {
				return array_key_exists( $key, $GLOBALS['options'] )
					? $GLOBALS['options'][ $key ]
					: $default;
			}
			function add_action( $hook, $callback, $priority = 10, $acceptedArgs = 1 ) {
				$GLOBALS['actions'][] = $hook;
			}
			function remove_action( $hook, $callback, $priority = 10 ) {
				$GLOBALS['removed_actions'][] = $hook;
				$key = array_search( $hook, $GLOBALS['actions'], true );
				if ( $key !== false ) { unset( $GLOBALS['actions'][ $key ] ); }
			}
			function wp_mail( $to, $subject, $plain, $headers = [], $attachments = [] ) {
				$GLOBALS['mail_html'] = $GLOBALS['rrze_appointment_html_body'] ?? '';
				if ( $GLOBALS['mail_should_throw'] ) { throw new \\RuntimeException( 'mail failed' ); }
				return true;
			}
			function wp_unslash( $value ) {
				if ( is_array( $value ) ) { return array_map( 'wp_unslash', $value ); }
				return is_string( $value ) ? stripslashes( $value ) : $value;
			}
			function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
			function sanitize_key( $value ) {
				return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $value ) );
			}

			require ${ JSON.stringify( settingsPath ) };

			$settings = new \\RRZE\\Appointment\\Settings();
			$settings->register();
			$registeredHooks = $GLOBALS['actions'];

			$GLOBALS['options'][ \\RRZE\\Appointment\\Settings::OPTION_NAME ] = 'invalid';
			$defaultReminderDays = \\RRZE\\Appointment\\Settings::get( 'reminder_days' );
			$GLOBALS['options'][ \\RRZE\\Appointment\\Settings::OPTION_NAME ] = [
				'reminder_days' => 0,
				'retention_days' => '',
				'recurrence_limit' => 0,
			];
			$storedReminderDays = \\RRZE\\Appointment\\Settings::get( 'reminder_days' );
			$blankRetentionDays = \\RRZE\\Appointment\\Settings::get( 'retention_days' );
			$sanitized = $settings->sanitize( [ 'reminder_days' => 99, 'retention_days' => -5 ] );
			$renderedTemplate = \\RRZE\\Appointment\\Settings::renderTemplate(
				'[name]|[message]|[invalid]',
				[ '[name]' => 'Ada', '[invalid]' => [ 'ignored' ] ]
			);

			$GLOBALS['actions'] = [];
			$GLOBALS['removed_actions'] = [];
			$mailResult = \\RRZE\\Appointment\\Settings::sendMail(
				'user@example.test',
				'Subject',
				'Plain',
				'<p>HTML</p>'
			);
			$successfulMail = [
				'result' => $mailResult,
				'wrappedHtml' => $GLOBALS['mail_html'],
				'hookRemoved' => in_array( 'phpmailer_init', $GLOBALS['removed_actions'], true )
					&& !in_array( 'phpmailer_init', $GLOBALS['actions'], true ),
				'globalRemoved' => !isset( $GLOBALS['rrze_appointment_html_body'] ),
			];

			$GLOBALS['actions'] = [];
			$GLOBALS['removed_actions'] = [];
			$GLOBALS['mail_should_throw'] = true;
			$failureMessage = '';
			try {
				\\RRZE\\Appointment\\Settings::sendMail(
					'user@example.test',
					'Subject',
					'Plain',
					'<p>HTML</p>'
				);
			} catch ( \\RuntimeException $exception ) {
				$failureMessage = $exception->getMessage();
			}
			$failedMail = [
				'message' => $failureMessage,
				'hookRemoved' => in_array( 'phpmailer_init', $GLOBALS['removed_actions'], true )
					&& !in_array( 'phpmailer_init', $GLOBALS['actions'], true ),
				'globalRemoved' => !isset( $GLOBALS['rrze_appointment_html_body'] ),
			];

			$GLOBALS['rrze_appointment_html_body'] = '<p>Prepared HTML</p>';
			$mailer = new \\PHPMailer\\PHPMailer\\PHPMailer();
			\\RRZE\\Appointment\\Settings::addHtmlPart( $mailer );
			$htmlMail = [
				'charset' => $mailer->CharSet,
				'plainBody' => $mailer->AltBody,
				'htmlBody' => $mailer->Body,
				'isHtml' => $mailer->isHtml,
			];

			$_POST = [
				'text' => ' <b>Hello</b> ',
				'key' => ' Hello WORLD! ',
				'int' => [ '7' ],
			];
			$_GET = [ 'scalar_flag' => '1', 'array_flag' => [ '1' ] ];
			$getPostText = new \\ReflectionMethod( \\RRZE\\Appointment\\Settings::class, 'getPostText' );
			$getPostInt = new \\ReflectionMethod( \\RRZE\\Appointment\\Settings::class, 'getPostInt' );
			$hasQueryFlag = new \\ReflectionMethod( \\RRZE\\Appointment\\Settings::class, 'hasQueryFlag' );
			$getAdminPageHooks = new \\ReflectionMethod(
				\\RRZE\\Appointment\\Settings::class,
				'getAdminPageHooks'
			);
			if ( PHP_VERSION_ID < 80100 ) {
				foreach ( [ $getPostText, $getPostInt, $hasQueryFlag, $getAdminPageHooks ] as $method ) {
					$method->setAccessible( true );
				}
			}

			echo json_encode( [
				'registeredHooks' => $registeredHooks,
				'defaultReminderDays' => $defaultReminderDays,
				'storedReminderDays' => $storedReminderDays,
				'blankRetentionDays' => $blankRetentionDays,
				'sanitized' => $sanitized,
				'renderedTemplate' => $renderedTemplate,
				'successfulMail' => $successfulMail,
				'failedMail' => $failedMail,
				'htmlMail' => $htmlMail,
				'requestValues' => [
					'text' => $getPostText->invoke( null, 'text', false ),
					'key' => $getPostText->invoke( null, 'key', true ),
					'invalidInt' => $getPostInt->invoke( null, 'int' ),
					'scalarFlag' => $hasQueryFlag->invoke( null, 'scalar_flag' ),
					'arrayFlag' => $hasQueryFlag->invoke( null, 'array_flag' ),
				],
				'adminHooks' => $getAdminPageHooks->invoke( null ),
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as SettingsResult;
};

describe( 'settings controller', () => {
	it( 'registers its hooks and normalizes persisted settings', () => {
		const result = getSettingsResult();

		expect( result.registeredHooks ).toEqual( [
			'admin_menu',
			'admin_init',
			'admin_init',
			'admin_init',
			'admin_init',
			'admin_print_footer_scripts',
			'admin_enqueue_scripts',
		] );
		expect( result.defaultReminderDays ).toBe( 0 );
		expect( result.storedReminderDays ).toBe( 0 );
		expect( result.blankRetentionDays ).toBe( 30 );
		expect( result.sanitized ).toEqual( {
			reminder_days: 7,
			recurrence_limit: 1,
			retention_days: 0,
		} );
	} );

	it( 'renders only scalar placeholder values', () => {
		expect( getSettingsResult().renderedTemplate ).toBe( 'Ada||' );
	} );

	it( 'cleans up temporary mail state on success and failure', () => {
		const result = getSettingsResult();

		expect( result.successfulMail ).toEqual( {
			result: true,
			wrappedHtml: 'neutral:Subject:<p>HTML</p>',
			hookRemoved: true,
			globalRemoved: true,
		} );
		expect( result.failedMail ).toEqual( {
			message: 'mail failed',
			hookRemoved: true,
			globalRemoved: true,
		} );
	} );

	it( 'prepares multipart mail and rejects non-scalar request values', () => {
		const result = getSettingsResult();

		expect( result.htmlMail ).toEqual( {
			charset: 'UTF-8',
			plainBody: 'Plain body',
			htmlBody: '<p>Prepared HTML</p>',
			isHtml: true,
		} );
		expect( result.requestValues ).toEqual( {
			text: 'Hello',
			key: 'helloworld',
			invalidInt: 0,
			scalarFlag: true,
			arrayFlag: false,
		} );
		expect( result.adminHooks ).toEqual( [
			'settings_page_rrze-appointment-settings',
			'toplevel_page_rrze-appointment-bookings',
		] );
	} );
} );
