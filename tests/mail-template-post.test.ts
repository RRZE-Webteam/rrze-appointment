import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type MailTemplatePostResult = {
	registeredMetaCount: number;
	plainMetaAllowed: boolean;
	htmlMetaAllowed: boolean;
	superAdminCanEditHtml: boolean;
	createdId: number;
	createdPost: Record< string, unknown >;
	protectedHtml: string | null;
	storedSubject: string;
	storedBody: string;
	storedHtml: string;
	invalidUpdateIsError: boolean;
	nonTemplateTitle: string;
	supportedTemplate: Record< string, string > | null;
	unsupportedTemplate: unknown;
	allTemplates: Array< Record< string, unknown > >;
	unknownDefault: Record< string, string >;
	pendingDefault: Record< string, string >;
	cancellationDefault: Record< string, string >;
};

const getMailTemplatePostResult = (): MailTemplatePostResult => {
	const classPath = resolve(
		process.cwd(),
		'includes/Mail/MailTemplatePost.php'
	);
	const php = `
		namespace RRZE\\Appointment {
			class AppointmentException extends \\Exception {}
		}
		namespace RRZE\\Appointment\\Mail {
			class MailTemplate {
				public static function detailsTable( array $rows ): string { return '<table></table>'; }
				public static function actionButton( string $url, string $label ): string {
					return '<a href="' . $url . '">' . $label . '</a>';
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			class WP_Post {
				public int $ID;
				public string $post_title;
				public string $post_status;
				public string $post_type;
				public function __construct( int $id, string $title, string $status, string $type ) {
					$this->ID = $id;
					$this->post_title = $title;
					$this->post_status = $status;
					$this->post_type = $type;
				}
			}
			class WP_Error {
				public string $code;
				public string $message;
				public function __construct( string $code, string $message ) {
					$this->code = $code;
					$this->message = $message;
				}
			}
			$GLOBALS['posts'] = [
				7 => new WP_Post( 7, 'Existing template', 'publish', 'rrze_appt_mail_tpl' ),
				99 => new WP_Post( 99, 'Ordinary page', 'publish', 'page' ),
			];
			$GLOBALS['post_meta'] = [
				7 => [
					'tpl_booking_pending_subject' => 'Stored subject',
					'tpl_booking_pending_body' => 'Stored body',
					'tpl_booking_pending_body_html' => '<p>Stored HTML</p>',
				],
			];
			$GLOBALS['registered_meta'] = [];
			$GLOBALS['is_multisite'] = true;
			$GLOBALS['is_super_admin'] = false;
			function __( $value, $domain ) { return $value; }
			function register_post_type( $type, $arguments ) {}
			function register_post_meta( $type, $key, $arguments ) {
				$GLOBALS['registered_meta'][ $key ] = $arguments;
			}
			function current_user_can( $capability ) { return true; }
			function is_multisite() { return $GLOBALS['is_multisite']; }
			function is_super_admin() { return $GLOBALS['is_super_admin']; }
			function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
			function get_post_meta( $id, $key, $single ) {
				return $GLOBALS['post_meta'][ $id ][ $key ] ?? '';
			}
			function update_post_meta( $id, $key, $value ) {
				$GLOBALS['post_meta'][ $id ][ $key ] = $value;
				return true;
			}
			function get_posts( $arguments ) {
				if ( ( $arguments['fields'] ?? '' ) === 'ids' ) { return []; }
				$statuses = (array) ( $arguments['post_status'] ?? [] );
				return array_values( array_filter(
					$GLOBALS['posts'],
					fn ( $post ) => $post->post_type === 'rrze_appt_mail_tpl'
						&& in_array( $post->post_status, $statuses, true )
				) );
			}
			function wp_insert_post( $data, $returnError ) {
				$id = 100;
				$GLOBALS['posts'][ $id ] = new WP_Post(
					$id, $data['post_title'], $data['post_status'], $data['post_type']
				);
				return $id;
			}
			function wp_update_post( $data, $returnError ) {
				$post = $GLOBALS['posts'][ $data['ID'] ];
				$post->post_title = $data['post_title'];
				$post->post_status = $data['post_status'];
				return $post->ID;
			}
			function is_wp_error( $value ) { return $value instanceof WP_Error; }
			function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
			function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
			function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
			function wp_kses_post( $value ) {
				return preg_replace( '#<script.*?</script>#is', '', $value );
			}
			require ${ JSON.stringify( classPath ) };

			$class = \\RRZE\\Appointment\\Mail\\MailTemplatePost::class;
			$class::register();
			$plainMetaAllowed = $GLOBALS['registered_meta']['tpl_booking_pending_subject']['auth_callback']();
			$htmlMetaAllowed = $GLOBALS['registered_meta']['tpl_booking_pending_body_html']['auth_callback']();
			$GLOBALS['is_super_admin'] = true;
			$superAdminCanEditHtml = $class::canEditHtml();
			$createdId = $class::save( [
				'title' => "  <b>New\\'s template</b>  ",
				'booking_pending_subject' => '<b>Pending subject</b>',
				'booking_pending_body' => " Plain <b>body</b> ",
				'booking_pending_body_html' => '<p>Protected HTML</p>',
			] );
			$protectedHtml = $GLOBALS['post_meta'][ $createdId ]['tpl_booking_pending_body_html'] ?? null;
			$class::save( [
				'id' => $createdId,
				'title' => 'Updated template',
				'booking_pending_subject' => 'Updated subject',
				'booking_pending_body' => 'Updated body',
				'booking_pending_body_html' => '<p>Allowed</p><script>bad()</script>',
			], false, true );
			$invalidUpdate = $class::save( [ 'id' => 99, 'title' => 'Compromised' ] );
			echo json_encode( [
				'registeredMetaCount' => count( $GLOBALS['registered_meta'] ),
				'plainMetaAllowed' => $plainMetaAllowed,
				'htmlMetaAllowed' => $htmlMetaAllowed,
				'superAdminCanEditHtml' => $superAdminCanEditHtml,
				'createdId' => $createdId,
				'createdPost' => (array) $GLOBALS['posts'][ $createdId ],
				'protectedHtml' => $protectedHtml,
				'storedSubject' => $GLOBALS['post_meta'][ $createdId ]['tpl_booking_pending_subject'],
				'storedBody' => $GLOBALS['post_meta'][ $createdId ]['tpl_booking_pending_body'],
				'storedHtml' => $GLOBALS['post_meta'][ $createdId ]['tpl_booking_pending_body_html'],
				'invalidUpdateIsError' => is_wp_error( $invalidUpdate ),
				'nonTemplateTitle' => $GLOBALS['posts'][99]->post_title,
				'supportedTemplate' => $class::getTemplateForType( 7, 'booking_pending' ),
				'unsupportedTemplate' => $class::getTemplateForType( 7, '../private' ),
				'allTemplates' => $class::getAll(),
				'unknownDefault' => $class::getDefault( 'unknown' ),
				'pendingDefault' => $class::getDefault( 'booking_pending' ),
				'cancellationDefault' => $class::getDefault( 'cancellation' ),
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as MailTemplatePostResult;
};

describe( 'mail template posts', () => {
	it( 'registers field permissions with HTML restricted on multisite', () => {
		const result = getMailTemplatePostResult();

		expect( result.registeredMetaCount ).toBe( 27 );
		expect( result.plainMetaAllowed ).toBe( true );
		expect( result.htmlMetaAllowed ).toBe( false );
		expect( result.superAdminCanEditHtml ).toBe( true );
	} );

	it( 'fails closed for HTML and sanitizes explicitly authorized updates', () => {
		const result = getMailTemplatePostResult();

		expect( result.createdId ).toBe( 100 );
		expect( result.protectedHtml ).toBeNull();
		expect( result.storedSubject ).toBe( 'Updated subject' );
		expect( result.storedBody ).toBe( 'Updated body' );
		expect( result.storedHtml ).toBe( '<p>Allowed</p>' );
	} );

	it( 'never updates a post belonging to another post type', () => {
		const result = getMailTemplatePostResult();

		expect( result.invalidUpdateIsError ).toBe( true );
		expect( result.nonTemplateTitle ).toBe( 'Ordinary page' );
	} );

	it( 'reads only supported fields and exposes normalized template lists', () => {
		const result = getMailTemplatePostResult();

		expect( result.supportedTemplate ).toEqual( {
			subject: 'Stored subject',
			body: 'Stored body',
			body_html: '<p>Stored HTML</p>',
		} );
		expect( result.unsupportedTemplate ).toBeNull();
		expect(
			result.allTemplates.map( ( template ) => template.id )
		).toEqual( [ 7, 100 ] );
		expect( result.unknownDefault ).toEqual( {
			subject: '',
			body: '',
			body_html: '',
		} );
		expect( result.pendingDefault.subject ).toContain(
			'Confirm appointment request'
		);
		expect( result.cancellationDefault.body ).toContain(
			'[cancellation_reason]'
		);
		expect( result.cancellationDefault.body_html ).toContain(
			'[cancellation_reason]'
		);
	} );
} );
