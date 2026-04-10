import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { generateTimeSlots, renderGroupedSlotsAccordion } from './utils';

export default function Save({ attributes }) {
    const { title, location, description, personId, tplId, locationUrl, color, style, bookingCutoff } = attributes;
    const slots = generateTimeSlots(attributes);
    const colorClass = color ? `is-${color}` : '';
    const styleClass = style === 'dark' ? 'is-style-dark' : 'is-style-light';
    const className = ['rrze-appointment', styleClass, colorClass].filter(Boolean).join(' ');

    return (
        <form className={className} method="post" action=""
            data-title={title}
            data-location={location}
            data-person-id={personId || 0}
            data-tpl-id={tplId || 0}
            data-booking-cutoff={bookingCutoff || 0}
        >
            <fieldset className="rrze-appointment__fieldset">
                {title && <legend className="rrze-appointment__title">{title}</legend>}
                {description && (
                    <p className="rrze-appointment__description">{description}</p>
                )}
                {location && (
                    <p className="rrze-appointment__location">
                        {__('Location:', 'rrze-appointment')} {/^https?:\/\//.test(location) ? (
                            <a href={location} target="_blank" rel="noopener noreferrer">{location}</a>
                        ) : locationUrl ? (
                            <a href={locationUrl} target="_blank" rel="noopener noreferrer">{location}</a>
                        ) : location}
                    </p>
                )}

                {slots.length > 0 ? (
                    <Fragment>
                        <div className="rrze-appointment__calendar" />

                        <div className="rrze-appointment__day-slots is-hidden">
                            <p className="rrze-appointment__day-slots-title">{__('Times on selected day', 'rrze-appointment')}</p>
                            <div className="rrze-appointment__day-slots-list" />
                        </div>

                        {renderGroupedSlotsAccordion(slots, 'rrze_appointment_slot')}

                        <div className="rrze-appointment__selected-info is-hidden" aria-live="polite" />
                    </Fragment>
                ) : (
                    <p className="rrze-appointment__missing-slot">{__('No time slots available.', 'rrze-appointment')}</p>
                )}
            </fieldset>
        </form>
    );
}
