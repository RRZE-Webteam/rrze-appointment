import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { generateTimeSlots } from './utils';

export default function Save( { attributes } ) {
	const {
		title,
		location,
		description,
		personId,
		personEmail,
		tplId,
		locationUrl,
		color,
		style,
		bookingCutoff,
	} = attributes;
	const slots = generateTimeSlots( attributes );
	const colorClass = color ? `is-${ color }` : '';
	const styleClass = style === 'dark' ? 'is-style-dark' : 'is-style-light';
	const className = [ 'rrze-appointment', styleClass, colorClass ]
		.filter( Boolean )
		.join( ' ' );
	let locationContent = location;
	if ( /^https?:\/\//.test( location ) ) {
		locationContent = <a href={ location }>{ location }</a>;
	} else if ( locationUrl ) {
		locationContent = <a href={ locationUrl }>{ location }</a>;
	}

	return (
		<form
			className={ className }
			method="post"
			action=""
			data-title={ title }
			data-location={ location }
			data-person-id={ personId || 0 }
			data-person-email={ personEmail || '' }
			data-tpl-id={ tplId || 0 }
			data-booking-cutoff={ bookingCutoff || 0 }
		>
			<fieldset className="rrze-appointment__fieldset">
				<legend
					className={
						title
							? 'rrze-appointment__title'
							: 'rrze-appointment__visually-hidden'
					}
				>
					{ title || __( 'Appointment booking', 'rrze-appointment' ) }
				</legend>
				{ description && (
					<p className="rrze-appointment__description">
						{ description }
					</p>
				) }
				{ location && (
					<p className="rrze-appointment__location">
						{ __( 'Location:', 'rrze-appointment' ) }{ ' ' }
						{ locationContent }
					</p>
				) }

				{ slots.length > 0 ? (
					<Fragment>
						<div className="rrze-appointment__calendar" />
						<p
							className="rrze-appointment__availability-status is-hidden"
							role="status"
							aria-live="polite"
						/>

						<div className="rrze-appointment__day-slots is-hidden">
							<h3 className="rrze-appointment__day-slots-title">
								{ __(
									'Times on selected day',
									'rrze-appointment'
								) }
							</h3>
							<div className="rrze-appointment__day-slots-list" />
						</div>

						<div
							className="rrze-appointment__slot-data"
							hidden
							aria-hidden="true"
						>
							{ slots.map( ( slot ) => (
								<input
									key={ slot.value }
									type="radio"
									name="rrze_appointment_slot"
									value={ slot.value }
									data-label={ slot.timeRange }
								/>
							) ) }
						</div>

						<p
							className="rrze-appointment__selected-info is-hidden"
							role="status"
							aria-live="polite"
						/>
					</Fragment>
				) : (
					<p className="rrze-appointment__missing-slot">
						{ __( 'No time slots available.', 'rrze-appointment' ) }
					</p>
				) }
			</fieldset>
		</form>
	);
}
