/**
 * Contextual setup tour for settings and bookings.
 */
/* global rrzeAppointmentGuide */
import {
	createPortal,
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SetupTourStepPanel } from './setup-tour-step';

function getSetupSteps() {
	return [
		{
			id: 'tab-general',
			number: 1,
			tab: 'general',
			screen: 'settings',
			target: '[data-rrze-tour="tab-general"]',
			title: __( 'General settings', 'rrze-appointment' ),
			text: __(
				'Open the General tab to configure reminder emails and the recurrence limit for repeating appointments.',
				'rrze-appointment'
			),
		},
		{
			id: 'reminder-days',
			number: 2,
			tab: 'general',
			screen: 'settings',
			target: '[data-rrze-tour="reminder-days"]',
			title: __( 'Reminder email', 'rrze-appointment' ),
			text: __(
				'Choose how many days before an appointment reminder emails are sent to the host and the person who booked.',
				'rrze-appointment'
			),
		},
		{
			id: 'recurrence-limit',
			number: 3,
			tab: 'general',
			screen: 'settings',
			target: '[data-rrze-tour="recurrence-limit"]',
			title: __( 'Recurrence limit', 'rrze-appointment' ),
			text: __(
				'Set the maximum number of recurring appointment dates generated from a single block.',
				'rrze-appointment'
			),
		},
		{
			id: 'save-general',
			number: 4,
			tab: 'general',
			screen: 'settings',
			target: '[data-rrze-tour="save-settings"]',
			title: __( 'Save settings', 'rrze-appointment' ),
			text: __(
				'Click Save changes to store your general settings.',
				'rrze-appointment'
			),
		},
		{
			id: 'tab-templates',
			number: 5,
			tab: 'templates',
			screen: 'settings',
			target: '[data-rrze-tour="tab-templates"]',
			title: __( 'Mail templates tab', 'rrze-appointment' ),
			text: __(
				'Switch to Mail Templates to customize confirmation, reminder, and cancellation emails.',
				'rrze-appointment'
			),
		},
		{
			id: 'new-template',
			number: 6,
			tab: 'templates',
			screen: 'settings',
			target: '[data-rrze-tour="new-template"]',
			title: __( 'Create a template', 'rrze-appointment' ),
			text: __(
				'Create a custom mail template or use the built-in default. You can send test emails to verify the content.',
				'rrze-appointment'
			),
		},
		{
			id: 'bookings-intro',
			number: 7,
			tab: '',
			screen: 'bookings',
			target: '[data-rrze-tour="bookings-page"]',
			title: __( 'Appointments overview', 'rrze-appointment' ),
			text: __(
				'Open the Appointments menu to view, filter, and cancel bookings from the frontend.',
				'rrze-appointment'
			),
		},
		{
			id: 'bookings-filter',
			number: 8,
			tab: '',
			screen: 'bookings',
			target: '[data-rrze-tour="bookings-filter"]',
			title: __( 'Filter appointments', 'rrze-appointment' ),
			text: __(
				'Filter bookings by date range or person to quickly find specific appointments.',
				'rrze-appointment'
			),
		},
		{
			id: 'bookings-table',
			number: 9,
			tab: '',
			screen: 'bookings',
			target: '[data-rrze-tour="bookings-table"]',
			title: __( 'Manage bookings', 'rrze-appointment' ),
			text: __(
				'Each row shows date, time, title, host, and booker. Use Cancel booking to release a slot and notify participants.',
				'rrze-appointment'
			),
		},
	];
}

const TOUR_STEP_COUNT = 9;

function isTransitionStep( step ) {
	return isTabStep( step );
}

function hasArrivedAtStep( step ) {
	if ( ! step || ! isStepOnActiveScreen( step ) ) {
		return false;
	}

	if ( step.screen === 'settings' && ! isStepOnActiveTab( step ) ) {
		return false;
	}

	return Boolean( findStepTarget( step ) );
}

function skipCompletedTransitionSteps( steps, index, stepId ) {
	if ( ! stepId || index < 0 || index >= steps.length ) {
		return index;
	}

	const step = steps[ index ];

	if ( ! step || step.id !== stepId || ! isTransitionStep( step ) ) {
		return index;
	}

	if ( ! hasArrivedAtStep( step ) ) {
		return index;
	}

	return findNextStepIndex( steps, index );
}

function dismissSetupTour() {
	if ( typeof rrzeAppointmentGuide === 'undefined' ) {
		return Promise.resolve();
	}

	const body = new FormData();
	body.append( 'action', 'rrze_appointment_dismiss_setup_tour' );
	body.append( 'nonce', rrzeAppointmentGuide.setupTourNonce );

	return fetch( rrzeAppointmentGuide.ajaxUrl, {
		method: 'POST',
		body,
		credentials: 'same-origin',
	} );
}

function buildTourUrl( step ) {
	if ( step.screen === 'bookings' ) {
		const url = new URL(
			rrzeAppointmentGuide.bookingsUrl,
			window.location.origin
		);
		url.searchParams.set( 'rrze_setup_tour', '1' );
		url.searchParams.set( 'rrze_setup_tour_step', step.id );
		return url.toString();
	}

	const url = new URL(
		rrzeAppointmentGuide.settingsUrl,
		window.location.origin
	);
	url.searchParams.set( 'tab', step.tab );
	url.searchParams.set( 'rrze_setup_tour', '1' );
	url.searchParams.set( 'rrze_setup_tour_step', step.id );
	return url.toString();
}

function findStepTarget( step ) {
	return document.querySelector( step.target );
}

const SPOTLIGHT_PADDING = 8;
const TOUR_TARGET_CLASS = 'rrze-appointment-setup-tour__target';

function clearTourTargetMarkers() {
	document
		.querySelectorAll( `.${ TOUR_TARGET_CLASS }` )
		.forEach( ( element ) => {
			element.classList.remove( TOUR_TARGET_CLASS );
		} );
}

function markTourTarget( element ) {
	clearTourTargetMarkers();

	if ( element ) {
		element.classList.add( TOUR_TARGET_CLASS );
	}
}

function getCutoutClipPath( rect ) {
	const right = rect.left + rect.width;
	const bottom = rect.top + rect.height;
	const viewportWidth = window.innerWidth;
	const viewportHeight = window.innerHeight;

	return `polygon(
		0px 0px,
		${ viewportWidth }px 0px,
		${ viewportWidth }px ${ viewportHeight }px,
		0px ${ viewportHeight }px,
		0px 0px,
		${ rect.left }px ${ rect.top }px,
		${ rect.left }px ${ bottom }px,
		${ right }px ${ bottom }px,
		${ right }px ${ rect.top }px,
		${ rect.left }px ${ rect.top }px
	)`;
}

function getSpotlightRect( element ) {
	if ( ! element ) {
		return null;
	}

	const rect = element.getBoundingClientRect();
	if ( rect.width <= 0 || rect.height <= 0 ) {
		return null;
	}

	const pad = SPOTLIGHT_PADDING;

	return {
		top: Math.max( 0, rect.top - pad ),
		left: Math.max( 0, rect.left - pad ),
		width: rect.width + pad * 2,
		height: rect.height + pad * 2,
	};
}

function SetupTourSpotlight( { rect, onClose, closeLabel } ) {
	if ( ! rect ) {
		return (
			<button
				type="button"
				className="rrze-appointment-setup-tour__overlay"
				aria-label={ closeLabel }
				onClick={ onClose }
			/>
		);
	}

	return (
		<>
			<button
				type="button"
				className="rrze-appointment-setup-tour__overlay-panel rrze-appointment-setup-tour__overlay-panel--cutout"
				style={ { clipPath: getCutoutClipPath( rect ) } }
				aria-label={ closeLabel }
				onClick={ onClose }
			/>
			<div
				className="rrze-appointment-setup-tour__spotlight"
				style={ {
					top: rect.top,
					left: rect.left,
					width: rect.width,
					height: rect.height,
				} }
				aria-hidden="true"
			/>
		</>
	);
}

function resolveGlobalStepIndex( steps, stepId ) {
	if ( stepId ) {
		const resolved = steps.findIndex( ( step ) => step.id === stepId );

		return resolved >= 0 ? resolved : 0;
	}

	return 0;
}

function isTabStep( step ) {
	return step.id.startsWith( 'tab-' );
}

function isStepOnActiveScreen( step ) {
	return step.screen === rrzeAppointmentGuide.activeScreen;
}

function isStepOnActiveTab( step ) {
	if ( step.screen !== 'settings' ) {
		return true;
	}

	return step.tab === rrzeAppointmentGuide.activeTab;
}

function needsNavigationForStep( step ) {
	if ( ! isStepOnActiveScreen( step ) ) {
		return true;
	}

	if ( isTabStep( step ) ) {
		return false;
	}

	return ! isStepOnActiveTab( step );
}

function isStepTargetVisible( step ) {
	if ( isTabStep( step ) ) {
		return Boolean( findStepTarget( step ) );
	}

	if ( ! isStepOnActiveScreen( step ) || ! isStepOnActiveTab( step ) ) {
		return false;
	}

	return Boolean( findStepTarget( step ) );
}

function findNextStepIndex( steps, fromIndex ) {
	let index = fromIndex + 1;

	while ( index < steps.length ) {
		const step = steps[ index ];

		if ( ! step.optional || isStepTargetVisible( step ) ) {
			return index;
		}

		index++;
	}

	return fromIndex;
}

function findPreviousStepIndex( steps, fromIndex ) {
	let index = fromIndex - 1;

	while ( index >= 0 ) {
		const step = steps[ index ];

		if ( ! step.optional || isStepTargetVisible( step ) ) {
			return index;
		}

		index--;
	}

	return fromIndex;
}

export function SetupTour( { initialStepId = '', onClose } ) {
	const allSteps = useMemo( getSetupSteps, [] );
	const [ globalStepIndex, setGlobalStepIndex ] = useState( () => {
		const resolved = resolveGlobalStepIndex( allSteps, initialStepId );
		return skipCompletedTransitionSteps(
			allSteps,
			resolved,
			initialStepId
		);
	} );
	const [ anchor, setAnchor ] = useState( null );
	const [ spotlightRect, setSpotlightRect ] = useState( null );

	const currentStep = allSteps[ globalStepIndex ];
	const totalSteps = TOUR_STEP_COUNT;
	const stepNumber = currentStep?.number ?? globalStepIndex + 1;

	const goToGlobalStep = useCallback(
		( index, { switchContext = false } = {} ) => {
			if ( index < 0 || index >= allSteps.length ) {
				return;
			}

			const step = allSteps[ index ];
			const onSameScreen =
				step.screen === rrzeAppointmentGuide.activeScreen;
			const onSameTab =
				step.screen !== 'settings' ||
				step.tab === rrzeAppointmentGuide.activeTab;

			if ( ! onSameScreen || ! onSameTab ) {
				if ( isTabStep( step ) && ! switchContext ) {
					setGlobalStepIndex( index );
					return;
				}

				window.location.href = buildTourUrl( step );
				return;
			}

			setGlobalStepIndex( index );
		},
		[ allSteps ]
	);

	const syncAnchor = useCallback( () => {
		if ( ! currentStep ) {
			clearTourTargetMarkers();
			setAnchor( null );
			setSpotlightRect( null );
			return;
		}

		const target = findStepTarget( currentStep );

		if ( ! target ) {
			clearTourTargetMarkers();
			setAnchor( null );
			setSpotlightRect( null );
			return;
		}

		if (
			! isTabStep( currentStep ) &&
			( ! isStepOnActiveScreen( currentStep ) ||
				! isStepOnActiveTab( currentStep ) )
		) {
			clearTourTargetMarkers();
			setAnchor( null );
			setSpotlightRect( null );
			return;
		}

		markTourTarget( target );
		setAnchor( target );
		setSpotlightRect( getSpotlightRect( target ) );

		target.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
	}, [ currentStep ] );

	useEffect( () => {
		let frameId = window.requestAnimationFrame( () => {
			syncAnchor();
		} );

		const onLayoutChange = () => {
			window.cancelAnimationFrame( frameId );
			frameId = window.requestAnimationFrame( () => {
				syncAnchor();
			} );
		};

		window.addEventListener( 'resize', onLayoutChange );
		window.addEventListener( 'scroll', onLayoutChange, true );

		return () => {
			window.cancelAnimationFrame( frameId );
			window.removeEventListener( 'resize', onLayoutChange );
			window.removeEventListener( 'scroll', onLayoutChange, true );
			clearTourTargetMarkers();
		};
	}, [ syncAnchor, globalStepIndex ] );

	useEffect( () => {
		if ( ! anchor || ! currentStep || ! isTabStep( currentStep ) ) {
			return undefined;
		}

		const onTabClick = ( event ) => {
			event.preventDefault();
			goToGlobalStep( globalStepIndex, { switchContext: true } );
		};

		anchor.addEventListener( 'click', onTabClick );

		return () => {
			anchor.removeEventListener( 'click', onTabClick );
		};
	}, [ anchor, currentStep, globalStepIndex, goToGlobalStep ] );

	const finishTour = () => {
		dismissSetupTour();
		onClose?.();

		const url = new URL( window.location.href );
		url.searchParams.delete( 'rrze_setup_tour' );
		url.searchParams.delete( 'rrze_setup_tour_step' );
		window.history.replaceState( {}, '', url.toString() );
	};

	if ( ! currentStep || totalSteps === 0 ) {
		return null;
	}

	const needsNavigation = needsNavigationForStep( currentStep );
	const nextStepIndex = findNextStepIndex( allSteps, globalStepIndex );
	const isLast = nextStepIndex === globalStepIndex;
	const stepText =
		needsNavigation && ! spotlightRect
			? __(
					'Continue to the next section to see the highlighted field.',
					'rrze-appointment'
			  )
			: currentStep.text;
	const nextLabel = ( () => {
		if (
			isTabStep( currentStep ) &&
			( ! isStepOnActiveScreen( currentStep ) ||
				! isStepOnActiveTab( currentStep ) )
		) {
			return __( 'Open', 'rrze-appointment' );
		}
		if ( needsNavigation ) {
			return __( 'Continue', 'rrze-appointment' );
		}
		return __( 'Next', 'rrze-appointment' );
	} )();

	const handleNext = () => {
		if ( isLast ) {
			finishTour();
			return;
		}

		if ( needsNavigation ) {
			goToGlobalStep( globalStepIndex, { switchContext: true } );
			return;
		}

		if (
			isTabStep( currentStep ) &&
			( ! isStepOnActiveScreen( currentStep ) ||
				! isStepOnActiveTab( currentStep ) )
		) {
			goToGlobalStep( globalStepIndex, { switchContext: true } );
			return;
		}

		goToGlobalStep( nextStepIndex );
	};

	return createPortal(
		<>
			<SetupTourSpotlight
				rect={ spotlightRect }
				onClose={ finishTour }
				closeLabel={ __( 'Close setup tour', 'rrze-appointment' ) }
			/>
			<div
				className="rrze-appointment-setup-tour__card"
				role="dialog"
				aria-modal="true"
				aria-label={ currentStep.title }
			>
				<SetupTourStepPanel
					stepNumber={ stepNumber }
					totalSteps={ totalSteps }
					title={ currentStep.title }
					text={ stepText }
					showPrevious={ globalStepIndex > 0 }
					isLast={ isLast }
					nextLabel={ nextLabel }
					onPrevious={ () =>
						goToGlobalStep(
							findPreviousStepIndex( allSteps, globalStepIndex )
						)
					}
					onSkip={ finishTour }
					onNext={ handleNext }
				/>
			</div>
		</>,
		document.body
	);
}
