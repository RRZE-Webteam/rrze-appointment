import { registerBlockType } from '@wordpress/blocks';
import { Fragment } from '@wordpress/element';
import './style.css';
import Edit from './edit';
import { generateTimeSlots, renderGroupedSlotsAccordion } from './utils';

registerBlockType('rrze/appointment', {
    edit: Edit,

    save({ attributes }) {
        const { title, location, description } = attributes;
        const slots = generateTimeSlots(attributes);

        return (
            <form className="rrze-appointment" method="post" action="">
                {title && <h3 className="rrze-appointment__title">{title}</h3>}
                {description && (
                    <div className="rrze-appointment__description">{description}</div>
                )}
                {location && (
                    <p className="rrze-appointment__location">{location}</p>
                )}

                {slots.length > 0 ? (
                    <Fragment>
                        <div className="rrze-appointment__calendar" />

                        <fieldset className="rrze-appointment__day-slots is-hidden">
                            <legend>Uhrzeiten am ausgewählten Tag</legend>
                            <div className="rrze-appointment__day-slots-list" />
                        </fieldset>

                        {renderGroupedSlotsAccordion(slots, 'rrze_appointment_slot')}

                        <div className="rrze-appointment__selected-info is-hidden">
                            <p className="rrze-appointment__selected-text" />
                            <button type="button" className="rrze-appointment__book-button">Buchen</button>
                            <p className="rrze-appointment__book-status" />
                        </div>
                    </Fragment>
                ) : (
                    <p className="rrze-appointment__missing-slot">Keine Timeslots verfügbar.</p>
                )}
            </form>
        );
    }
});
