import { Fragment } from '@wordpress/element';
import { generateTimeSlots, renderGroupedSlotsAccordion } from './utils';

export default function Save({ attributes }) {
    const { title, location, description, personId, tplId, locationUrl, color, style } = attributes;
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
        >
            {title && <h3 className="rrze-appointment__title">{title}</h3>}
            {description && (
                <div className="rrze-appointment__description">{description}</div>
            )}
            {location && (
                <p className="rrze-appointment__location">
                    Ort: {location}
                    {locationUrl && (
                        <> (<a href={locationUrl} target="_blank" rel="noopener noreferrer">Auf der Karte ansehen</a>)</>
                    )}
                </p>
            )}

            {slots.length > 0 ? (
                <Fragment>
                    <div className="rrze-appointment__calendar" />

                    <fieldset className="rrze-appointment__day-slots is-hidden">
                        <legend>Uhrzeiten am ausgewählten Tag</legend>
                        <div className="rrze-appointment__day-slots-list" />
                    </fieldset>

                    {renderGroupedSlotsAccordion(slots, 'rrze_appointment_slot')}

                    <div className="rrze-appointment__selected-info is-hidden" aria-live="polite" />
                </Fragment>
            ) : (
                <p className="rrze-appointment__missing-slot">Keine Timeslots verfügbar.</p>
            )}
        </form>
    );
}
