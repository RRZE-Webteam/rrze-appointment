import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const readProjectFile = ( path: string ) =>
	readFileSync( resolve( process.cwd(), path ), 'utf8' );

describe( 'booking opening notification workflow', () => {
	const main = readProjectFile( 'includes/Main.php' );
	const controller = readProjectFile(
		'includes/Controller/BookingOpeningController.php'
	);
	const notifier = readProjectFile(
		'includes/Notification/BookingOpeningNotifier.php'
	);
	const renderer = readProjectFile(
		'includes/Presentation/PublicPageRenderer.php'
	);
	const settings = readProjectFile(
		'includes/Configuration/PluginSettings.php'
	);
	const templates = readProjectFile( 'includes/Mail/MailTemplatePost.php' );

	it( 'registers subscriptions and schedules their opening email', () => {
		expect( main ).toContain( 'rrze_appointment_notify_opening' );
		expect( main ).toContain( 'BookingOpeningNotifier::CRON_HOOK' );
		expect( controller ).toContain( 'BookingOpeningNotifier::subscribe(' );
		expect( notifier ).toContain( 'wp_schedule_single_event(' );
	} );

	it( 'claims a slot through the regular pending confirmation workflow', () => {
		expect( notifier ).toContain(
			'TokenManager::createPending($slot, $meta)'
		);
		expect( notifier ).toContain(
			'TokenManager::confirmUrl($pendingToken)'
		);
		expect( notifier ).toContain( 'TokenManager::getPendingSlots()' );
		expect( notifier ).toContain( "'status_token' => $statusToken" );
		expect( notifier ).toContain( 'getSubscriptionByStatusToken' );
	} );

	it( 'uses the notification illustration on the website, not in email', () => {
		expect( templates ).toContain( "'booking_opening_notification'" );
		expect( templates ).not.toContain( '[notification_image]' );
		expect( notifier ).not.toContain( 'notification-36.png' );
		expect( settings ).toContain(
			"'opening_notification' => 'notification-36.png'"
		);
		expect( renderer ).toContain(
			"getIllustrationUrl('opening_notification')"
		);
		expect( renderer ).toContain( 'renderOpeningNotificationSuccess' );
	} );
} );
