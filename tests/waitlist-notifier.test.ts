import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

type Notification = {
	availableSlot: string;
	attributes: Record< string, unknown >;
	bookedSlot: string;
	bookedMeta: Record< string, unknown >;
};

type WaitlistNotifierResult = {
	attempts: Notification[];
	notifications: Notification[];
	revisionIgnored: boolean;
	draftIgnored: boolean;
	parseFailureSuppressed: boolean;
	corruptOptionsIgnored: boolean;
};

const getWaitlistNotifierResult = (): WaitlistNotifierResult => {
	const notifierPath = resolve(
		process.cwd(),
		'includes/Notification/WaitlistNotifier.php'
	);
	const php = `
		namespace {
			class WP_Post {
				public $post_status;
				public $post_content;
				public function __construct( $status, $content ) {
					$this->post_status = $status;
					$this->post_content = $content;
				}
			}
		}
		namespace RRZE\\Appointment\\Booking {
			class Bookings {
				public const META_OPTION = 'booking_meta';
				public const SLOTS_OPTION = 'booked_slots';
				public static $attempts = [];
				public static $notifications = [];
				public static function sendWaitlistNotificationStatic(
					string $availableSlot,
					array $attributes,
					string $bookedSlot,
					array $bookedMeta
				): void {
					$notification = compact(
						'availableSlot',
						'attributes',
						'bookedSlot',
						'bookedMeta'
					);
					self::$attempts[] = $notification;
					if ( !empty( $bookedMeta['throw'] ) ) {
						throw new \\RuntimeException( 'mail failed' );
					}
					self::$notifications[] = $notification;
				}
			}
			class SlotGenerator {
				public static function fromAttributes( array $attributes ): array {
					if ( !empty( $attributes['throw'] ) ) {
						throw new \\RuntimeException( 'invalid block' );
					}
					return is_array( $attributes['slots'] ?? null )
						? $attributes['slots']
						: [];
				}
			}
		}
		namespace {
			define( 'ABSPATH', __DIR__ );
			$GLOBALS['is_revision'] = false;
			$GLOBALS['is_autosave'] = false;
			$GLOBALS['options'] = [
				'booking_meta' => [
					'2026-09-15 10:00-10:30' => [
						'booker_waitlist' => true,
						'person_id' => 5,
						'booker_email' => 'first@example.test',
						'throw' => true,
					],
					'2026-09-07 10:00-10:30' => [
						'booker_waitlist' => true,
						'person_id' => 5,
						'booker_email' => 'second@example.test',
					],
					'2026-09-05 08:00-08:30' => [
						'booker_waitlist' => true,
						'person_id' => 5,
					],
					'2026-09-08 10:00-10:30' => [
						'booker_waitlist' => true,
						'person_id' => 6,
					],
					'2026-08-20 10:00-10:30' => [
						'booker_waitlist' => true,
						'person_id' => 5,
					],
					'invalid-slot' => [ 'booker_waitlist' => true, 'person_id' => 5 ],
					'2026-09-09 10:00-10:30' => 'invalid-meta',
					'2026-09-10 10:00-10:30' => [
						'booker_waitlist' => true,
						'person_id' => 0,
					],
				],
				'booked_slots' => [
					'2026-09-04 09:00-09:30',
					[ 'invalid' ],
					42,
				],
			];

			function wp_is_post_revision( $postId ) { return $GLOBALS['is_revision']; }
			function wp_is_post_autosave( $postId ) { return $GLOBALS['is_autosave']; }
			function has_blocks( $content ) { return $content !== 'no-blocks'; }
			function current_time( $format ) { return '2026-08-21'; }
			function get_option( $key, $default = false ) {
				return array_key_exists( $key, $GLOBALS['options'] )
					? $GLOBALS['options'][ $key ]
					: $default;
			}
			function parse_blocks( $content ) {
				if ( $content === 'parse-error' ) { throw new \\RuntimeException( 'parse failed' ); }
				if ( $content === 'before' ) {
					return [
						[
							'blockName' => 'rrze/appointment',
							'attrs' => [
								'personId' => 5,
								'marker' => 'before-5',
								'slots' => [
									'2026-09-10 09:00-09:30',
								],
							],
						],
					];
				}
				return [
					'invalid-block',
					[
						'blockName' => 'core/group',
						'innerBlocks' => [
							[
								'blockName' => 'rrze/appointment',
								'attrs' => [ 'personId' => 5, 'throw' => true ],
							],
							[
								'blockName' => 'rrze/appointment',
								'attrs' => [
									'personId' => 5,
									'marker' => 'after-5',
									'slots' => [
										'2026-09-10 09:00-09:30',
										'2026-09-06 09:00-09:30',
										'2026-09-05 09:00-09:30',
										'2026-09-04 09:00-09:30',
										'2026-08-20 09:00-09:30',
										'invalid-slot',
									],
								],
							],
							[
								'blockName' => 'rrze/appointment',
								'attrs' => [
									'personId' => 6,
									'marker' => 'after-6',
									'slots' => [ '2026-09-03 09:00-09:30' ],
								],
							],
							[
								'blockName' => 'rrze/appointment',
								'attrs' => [ 'personId' => 0, 'slots' => [ '2026-09-01 09:00-09:30' ] ],
							],
							[
								'blockName' => 'rrze/appointment',
								'attrs' => 'invalid',
							],
						],
					],
				];
			}

			require ${ JSON.stringify( notifierPath ) };

			$notifier = new \\RRZE\\Appointment\\Notification\\WaitlistNotifier();
			$notifier->handlePostUpdated(
				10,
				new WP_Post( 'publish', 'after' ),
				new WP_Post( 'publish', 'before' )
			);
			$attempts = \\RRZE\\Appointment\\Booking\\Bookings::$attempts;
			$notifications = \\RRZE\\Appointment\\Booking\\Bookings::$notifications;

			\\RRZE\\Appointment\\Booking\\Bookings::$attempts = [];
			$GLOBALS['is_revision'] = true;
			$notifier->handlePostUpdated(
				10,
				new WP_Post( 'publish', 'after' ),
				new WP_Post( 'publish', 'before' )
			);
			$revisionIgnored = \\RRZE\\Appointment\\Booking\\Bookings::$attempts === [];

			$GLOBALS['is_revision'] = false;
			$notifier->handlePostUpdated(
				10,
				new WP_Post( 'draft', 'after' ),
				new WP_Post( 'publish', 'before' )
			);
			$draftIgnored = \\RRZE\\Appointment\\Booking\\Bookings::$attempts === [];

			$parseFailureSuppressed = true;
			try {
				$notifier->handlePostUpdated(
					10,
					new WP_Post( 'publish', 'parse-error' ),
					new WP_Post( 'draft', 'before' )
				);
			} catch ( \\Throwable $exception ) {
				$parseFailureSuppressed = false;
			}

			$GLOBALS['options']['booking_meta'] = 'corrupt';
			$notifier->handlePostUpdated(
				10,
				new WP_Post( 'publish', 'after' ),
				new WP_Post( 'publish', 'before' )
			);
			$corruptOptionsIgnored = \\RRZE\\Appointment\\Booking\\Bookings::$attempts === [];

			echo json_encode( [
				'attempts' => $attempts,
				'notifications' => $notifications,
				'revisionIgnored' => $revisionIgnored,
				'draftIgnored' => $draftIgnored,
				'parseFailureSuppressed' => $parseFailureSuppressed,
				'corruptOptionsIgnored' => $corruptOptionsIgnored,
			] );
		}
	`;

	return JSON.parse(
		execFileSync( 'php', [ '-r', php ], { encoding: 'utf8' } )
	) as WaitlistNotifierResult;
};

describe( 'waitlist notifier', () => {
	it( 'selects the earliest newly added, unbooked slot for each host', () => {
		const result = getWaitlistNotifierResult();

		expect( result.attempts ).toHaveLength( 3 );
		expect(
			result.attempts.map( ( entry ) => entry.availableSlot )
		).toEqual( [
			'2026-09-05 09:00-09:30',
			'2026-09-05 09:00-09:30',
			'2026-09-03 09:00-09:30',
		] );
		expect( result.attempts.map( ( entry ) => entry.bookedSlot ) ).toEqual(
			[
				'2026-09-15 10:00-10:30',
				'2026-09-07 10:00-10:30',
				'2026-09-08 10:00-10:30',
			]
		);
		expect( result.attempts[ 1 ].attributes ).toMatchObject( {
			marker: 'after-5',
		} );
		expect( result.attempts[ 2 ].attributes ).toMatchObject( {
			marker: 'after-6',
		} );
	} );

	it( 'continues notifying after one mail failure', () => {
		const result = getWaitlistNotifierResult();

		expect( result.notifications ).toHaveLength( 2 );
		expect(
			result.notifications.map(
				( entry ) => entry.bookedMeta.booker_email
			)
		).toEqual( [ 'second@example.test', undefined ] );
	} );

	it( 'ignores revisions, drafts, corrupt options, and parser failures', () => {
		const result = getWaitlistNotifierResult();

		expect( result.revisionIgnored ).toBe( true );
		expect( result.draftIgnored ).toBe( true );
		expect( result.parseFailureSuppressed ).toBe( true );
		expect( result.corruptOptionsIgnored ).toBe( true );
	} );
} );
