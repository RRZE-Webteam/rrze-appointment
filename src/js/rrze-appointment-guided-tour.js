/**
 * RRZE Appointment admin tours: overview Guide + contextual setup tour.
 */
/* global rrzeAppointmentGuide */
import domReady from '@wordpress/dom-ready';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { Guide } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { SetupTour } from './setup-tour';

function GuideIcon( { dashicon } ) {
	return (
		<div className="rrze-appointment-guided-tour__icon" aria-hidden="true">
			<span className={ `dashicons ${ dashicon }` } />
		</div>
	);
}

function dismissTour() {
	if ( typeof rrzeAppointmentGuide === 'undefined' ) {
		return Promise.resolve();
	}

	const body = new FormData();
	body.append( 'action', 'rrze_appointment_dismiss_guided_tour' );
	body.append( 'nonce', rrzeAppointmentGuide.nonce );

	return fetch( rrzeAppointmentGuide.ajaxUrl, {
		method: 'POST',
		body,
		credentials: 'same-origin',
	} );
}

function ToursApp( { autoStartGuide, autoStartSetup, setupTourStepId } ) {
	const setupTourActive =
		Boolean( autoStartSetup ) || setupTourStepId.length > 0;
	const [ guideOpen, setGuideOpen ] = useState(
		Boolean( autoStartGuide ) && ! setupTourActive
	);
	const [ setupOpen, setSetupOpen ] = useState( setupTourActive );
	const [ setupTourKey, setSetupTourKey ] = useState( 0 );
	const [ setupStepId, setSetupStepId ] = useState( setupTourStepId );

	useEffect( () => {
		const onClick = ( event ) => {
			if ( event.target.closest( '#rrze-appointment-start-guided-tour' ) ) {
				event.preventDefault();
				setSetupOpen( false );
				setGuideOpen( true );
				return;
			}

			if ( event.target.closest( '#rrze-appointment-start-setup-tour' ) ) {
				event.preventDefault();
				setGuideOpen( false );
				setSetupStepId( '' );
				setSetupTourKey( ( key ) => key + 1 );
				setSetupOpen( true );
			}
		};

		document.addEventListener( 'click', onClick );

		return () => {
			document.removeEventListener( 'click', onClick );
		};
	}, [] );

	const finishGuide = () => {
		setGuideOpen( false );
		dismissTour();
	};

	const guidePages = [
		{
			image: <GuideIcon dashicon="dashicons-welcome-learn-more" />,
			content: (
				<>
					<h1 className="rrze-appointment-guided-tour__heading">
						{ __(
							'Welcome to RRZE Appointment',
							'rrze-appointment'
						) }
					</h1>
					<p className="rrze-appointment-guided-tour__text">
						{ __(
							'This plugin lets you offer bookable appointment slots on your website — with calendar view, email confirmations, and optional FAUdir integration.',
							'rrze-appointment'
						) }
					</p>
				</>
			),
		},
		{
			image: <GuideIcon dashicon="dashicons-calendar-alt" />,
			content: (
				<>
					<h1 className="rrze-appointment-guided-tour__heading">
						{ __(
							'Create appointments with the block',
							'rrze-appointment'
						) }
					</h1>
					<p className="rrze-appointment-guided-tour__text">
						{ __(
							'Insert the RRZE Appointment block in the editor, select dates and times, assign a host, and publish the page.',
							'rrze-appointment'
						) }
					</p>
				</>
			),
		},
		{
			image: <GuideIcon dashicon="dashicons-email-alt" />,
			content: (
				<>
					<h1 className="rrze-appointment-guided-tour__heading">
						{ __( 'Customize mail templates', 'rrze-appointment' ) }
					</h1>
					<p className="rrze-appointment-guided-tour__text">
						{ __(
							'Under Settings → RRZE Appointment → Mail Templates you can adapt confirmation, reminder, and cancellation emails using placeholders.',
							'rrze-appointment'
						) }
					</p>
				</>
			),
		},
		{
			image: <GuideIcon dashicon="dashicons-list-view" />,
			content: (
				<>
					<h1 className="rrze-appointment-guided-tour__heading">
						{ __( 'Manage bookings', 'rrze-appointment' ) }
					</h1>
					<p className="rrze-appointment-guided-tour__text">
						{ __(
							'Use the Appointments menu to review all bookings, filter by date or person, and cancel appointments when needed.',
							'rrze-appointment'
						) }
					</p>
				</>
			),
		},
	];

	return (
		<>
			{ guideOpen && (
				<Guide
					className="rrze-appointment-guided-tour"
					contentLabel={ __(
						'RRZE Appointment guided tour',
						'rrze-appointment'
					) }
					finishButtonText={ __( 'Get started', 'rrze-appointment' ) }
					onFinish={ finishGuide }
					pages={ guidePages }
				/>
			) }
			{ setupOpen && (
				<SetupTour
					key={ setupTourKey }
					initialStepId={ setupStepId }
					onClose={ () => setSetupOpen( false ) }
				/>
			) }
		</>
	);
}

domReady( () => {
	const container = document.getElementById(
		'rrze-appointment-guided-tour-root'
	);

	if ( ! container || typeof rrzeAppointmentGuide === 'undefined' ) {
		return;
	}

	createRoot( container ).render(
		<ToursApp
			autoStartGuide={ rrzeAppointmentGuide.autoStart }
			autoStartSetup={ rrzeAppointmentGuide.autoStartSetup }
			setupTourStepId={ rrzeAppointmentGuide.setupTourStepId || '' }
		/>
	);
} );
