import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type SettingsResult = {
	defaultReminderDays: number;
	storedReminderDays: number;
	blankRetentionDays: number;
	defaultCancellationReasonEnabled: boolean;
	defaultSensitiveModeEnabled: boolean;
	sanitized: Record< string, unknown >;
	sanitizedEnabled: Record< string, unknown >;
	sanitizedIllustrationsTab: Record< string, unknown >;
	sanitizedGeneralTab: Record< string, unknown >;
	renderedTemplate: string;
	successfulMail: Record< string, unknown >;
	failedMail: Record< string, unknown >;
	htmlMail: Record< string, unknown >;
	requestValues: Record< string, unknown >;
};

const getSettingsResult = (): SettingsResult => {
	const pluginSettingsPath = resolve(
		process.cwd(),
		'includes/Configuration/PluginSettings.php'
	);
	const mailerPath = resolve( process.cwd(), 'includes/Mail/Mailer.php' );
	const requestPath = resolve( process.cwd(), 'includes/Admin/Request.php' );
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

			function get_option( $key, $default = false ) {
				return array_key_exists( $key, $GLOBALS['options'] )
					? $GLOBALS['options'][ $key ]
					: $default;
			}
			function add_action( $hook, $callback ) { $GLOBALS['actions'][] = $hook; }
			function remove_action( $hook, $callback ) {
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
			function absint( $value ) { return abs( (int) $value ); }
			function wp_attachment_is_image( $id ) { return $id === 42; }

			require ${ JSON.stringify( pluginSettingsPath ) };
			require ${ JSON.stringify( mailerPath ) };
			require ${ JSON.stringify( requestPath ) };

			$settings = \\RRZE\\Appointment\\Configuration\\PluginSettings::class;
			$mailerClass = \\RRZE\\Appointment\\Mail\\Mailer::class;
			$request = \\RRZE\\Appointment\\Admin\\Request::class;

			$GLOBALS['options'][ $settings::OPTION_NAME ] = 'invalid';
			$defaultReminderDays = $settings::get( 'reminder_days' );
			$defaultCancellationReasonEnabled = $settings::get( 'cancellation_reason_enabled' );
			$defaultSensitiveModeEnabled = $settings::get( 'sensitive_mode_enabled' );
			$GLOBALS['options'][ $settings::OPTION_NAME ] = [
				'reminder_days' => 0,
				'retention_days' => '',
				'recurrence_limit' => 0,
			];
			$storedReminderDays = $settings::get( 'reminder_days' );
			$blankRetentionDays = $settings::get( 'retention_days' );
			$sanitized = $settings::sanitize( [ 'reminder_days' => 99, 'retention_days' => -5 ] );
			$sanitizedEnabled = $settings::sanitize( [
				'cancellation_reason_enabled' => '1',
				'sensitive_mode_enabled' => '1',
				'illustrations' => [
					'confirmation_success' => '42',
					'error' => '99',
					'unknown_screen' => '42',
				],
			] );
			$GLOBALS['options'][ $settings::OPTION_NAME ] = [
				'reminder_days' => 3,
				'recurrence_limit' => 12,
				'retention_days' => 45,
				'cancellation_reason_enabled' => true,
				'sensitive_mode_enabled' => true,
				'illustrations' => [ 'confirmation_success' => 42 ],
			];
			$sanitizedIllustrationsTab = $settings::sanitize( [
				'_settings_scope' => 'illustrations',
				'illustrations' => [ 'error' => 42 ],
			] );
			$sanitizedGeneralTab = $settings::sanitize( [
				'_settings_scope' => 'general',
				'reminder_days' => 2,
				'retention_days' => 10,
			] );
			$renderedTemplate = $mailerClass::render(
				'[name]|[message]|[invalid]',
				[ '[name]' => 'Ada', '[invalid]' => [ 'ignored' ] ]
			);

			$mailResult = $mailerClass::send(
				'user@example.test', 'Subject', 'Plain', '<p>HTML</p>'
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
				$mailerClass::send( 'user@example.test', 'Subject', 'Plain', '<p>HTML</p>' );
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
			$phpmailer = new \\PHPMailer\\PHPMailer\\PHPMailer();
			$mailerClass::addHtmlPart( $phpmailer );
			$htmlMail = [
				'charset' => $phpmailer->CharSet,
				'plainBody' => $phpmailer->AltBody,
				'htmlBody' => $phpmailer->Body,
				'isHtml' => $phpmailer->isHtml,
			];

			$_POST = [ 'text' => ' <b>Hello</b> ', 'key' => ' Hello WORLD! ', 'int' => [ '7' ] ];
			$_GET = [ 'scalar_flag' => '1', 'array_flag' => [ '1' ] ];

			echo json_encode( compact(
				'defaultReminderDays',
				'storedReminderDays',
				'blankRetentionDays',
				'defaultCancellationReasonEnabled',
				'defaultSensitiveModeEnabled',
				'sanitized',
				'sanitizedEnabled',
				'sanitizedIllustrationsTab',
				'sanitizedGeneralTab',
				'renderedTemplate',
				'successfulMail',
				'failedMail',
				'htmlMail'
			) + [
				'requestValues' => [
					'text' => $request::postText( 'text' ),
					'key' => $request::postText( 'key', true ),
					'invalidInt' => $request::postInt( 'int' ),
					'scalarFlag' => $request::hasQueryFlag( 'scalar_flag' ),
					'arrayFlag' => $request::hasQueryFlag( 'array_flag' ),
				],
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as SettingsResult;
};

describe( 'plugin configuration and mail delivery', () => {
	it( 'normalizes persisted settings', () => {
		const result = getSettingsResult();

		expect( result.defaultReminderDays ).toBe( 0 );
		expect( result.storedReminderDays ).toBe( 0 );
		expect( result.blankRetentionDays ).toBe( 30 );
		expect( result.defaultCancellationReasonEnabled ).toBe( true );
		expect( result.defaultSensitiveModeEnabled ).toBe( false );
		expect( result.sanitized ).toEqual( {
			reminder_days: 7,
			recurrence_limit: 1,
			retention_days: 0,
			cancellation_reason_enabled: false,
			sensitive_mode_enabled: false,
			illustrations: [],
		} );
		expect( result.sanitizedEnabled.cancellation_reason_enabled ).toBe(
			true
		);
		expect( result.sanitizedEnabled.sensitive_mode_enabled ).toBe( true );
		expect( result.sanitizedEnabled.illustrations ).toEqual( {
			confirmation_success: 42,
		} );
		expect( result.sanitizedIllustrationsTab ).toEqual( {
			reminder_days: 3,
			recurrence_limit: 12,
			retention_days: 45,
			cancellation_reason_enabled: true,
			sensitive_mode_enabled: true,
			illustrations: { error: 42 },
		} );
		expect( result.sanitizedGeneralTab ).toEqual( {
			reminder_days: 2,
			recurrence_limit: 12,
			retention_days: 10,
			cancellation_reason_enabled: false,
			sensitive_mode_enabled: false,
			illustrations: { confirmation_success: 42 },
		} );
	} );

	it( 'renders scalar placeholders and cleans up multipart mail state', () => {
		const result = getSettingsResult();

		expect( result.renderedTemplate ).toBe( 'Ada||' );
		expect( result.successfulMail ).toMatchObject( {
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
		expect( result.htmlMail ).toEqual( {
			charset: 'UTF-8',
			plainBody: 'Plain body',
			htmlBody: '<p>Prepared HTML</p>',
			isHtml: true,
		} );
	} );

	it( 'rejects non-scalar admin request values', () => {
		expect( getSettingsResult().requestValues ).toEqual( {
			text: 'Hello',
			key: 'helloworld',
			invalidInt: 0,
			scalarFlag: true,
			arrayFlag: false,
		} );
	} );
} );
