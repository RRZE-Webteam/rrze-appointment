/**
 * Shared step panel for the contextual setup tour.
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export function SetupTourStepPanel( {
	stepNumber,
	totalSteps,
	title,
	text,
	onPrevious,
	onSkip,
	onNext,
	nextLabel,
	showPrevious,
	isLast,
} ) {
	return (
		<div className="rrze-appointment-setup-tour__body">
			<p className="rrze-appointment-setup-tour__step">
				{ sprintf(
					/* translators: 1: current step number, 2: total steps */
					__( 'Step %1$d of %2$d', 'rrze-appointment' ),
					stepNumber,
					totalSteps
				) }
			</p>
			<h2 className="rrze-appointment-setup-tour__title">{ title }</h2>
			<p className="rrze-appointment-setup-tour__text">{ text }</p>
			<div className="rrze-appointment-setup-tour__actions">
				{ showPrevious && (
					<Button variant="tertiary" onClick={ onPrevious }>
						{ __( 'Previous', 'rrze-appointment' ) }
					</Button>
				) }
				<Button variant="tertiary" onClick={ onSkip }>
					{ __( 'Skip tour', 'rrze-appointment' ) }
				</Button>
				<Button variant="primary" onClick={ onNext }>
					{ isLast ? __( 'Finish', 'rrze-appointment' ) : nextLabel }
				</Button>
			</div>
		</div>
	);
}
