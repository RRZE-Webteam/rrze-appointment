import { registerBlockType } from '@wordpress/blocks';
import {
    TextControl,
    TextareaControl,
    PanelBody
} from '@wordpress/components';
import { InspectorControls } from '@wordpress/block-editor';
import { Fragment } from '@wordpress/element';

registerBlockType('rrze/appointment', {
    edit({ attributes, setAttributes }) {
        const { title, date, time, location, description } = attributes;

        return (
            <Fragment>
                <InspectorControls>
                    <PanelBody title="Termin-Einstellungen" initialOpen={true}>
                        <TextControl
                            label="Titel"
                            value={title}
                            onChange={(value) => setAttributes({ title: value })}
                        />

                        <TextControl
                            label="Datum (YYYY-MM-DD)"
                            value={date}
                            onChange={(value) => setAttributes({ date: value })}
                        />

                        <TextControl
                            label="Uhrzeit"
                            value={time}
                            onChange={(value) => setAttributes({ time: value })}
                        />

                        <TextControl
                            label="Ort"
                            value={location}
                            onChange={(value) => setAttributes({ location: value })}
                        />

                        <TextareaControl
                            label="Beschreibung"
                            value={description}
                            onChange={(value) => setAttributes({ description: value })}
                        />
                    </PanelBody>
                </InspectorControls>

                <div className="rrze-appointment-block">
                    <h3>{title || 'Termin-Titel'}</h3>
                    {description && <p>{description}</p>}
                    {location && <p><strong>Ort:</strong> {location}</p>}

                    <form className="rrze-appointment__form">
                        <fieldset className="rrze-appointment__slots">
                            <legend>Termin auswählen</legend>
                            {date ? (
                                <label className="rrze-appointment__slot-option">
                                    <input
                                        type="radio"
                                        name="rrze_appointment_slot_preview"
                                        value={[date, time].filter(Boolean).join(' ')}
                                        required
                                    />
                                    <span>{date}{time ? `, ${time}` : ''}</span>
                                </label>
                            ) : (
                                <p>Bitte Datum (und optional Uhrzeit) setzen.</p>
                            )}
                        </fieldset>
                        <button type="submit" disabled={!date}>Termin buchen</button>
                    </form>
                </div>
            </Fragment>
        );
    },

    save({ attributes }) {
        const { title, date, time, location, description } = attributes;
        const slotValue = [date, time].filter(Boolean).join(' ');

        return (
            <form className="rrze-appointment" method="post" action="">
                {title && <h3 className="rrze-appointment__title">{title}</h3>}
                {description && (
                    <div className="rrze-appointment__description">
                        {description}
                    </div>
                )}
                {location && (
                    <p className="rrze-appointment__location">{location}</p>
                )}

                <fieldset className="rrze-appointment__slots">
                    <legend>Termin auswählen</legend>
                    {date ? (
                        <label className="rrze-appointment__slot-option">
                            <input
                                type="radio"
                                name="rrze_appointment_slot"
                                value={slotValue}
                                required
                            />
                            <span>{date}{time ? `, ${time}` : ''}</span>
                        </label>
                    ) : (
                        <p className="rrze-appointment__missing-slot">
                            Kein Termin verfügbar.
                        </p>
                    )}
                </fieldset>

                <button type="submit" className="rrze-appointment__submit" disabled={!date}>
                    Termin buchen
                </button>
            </form>
        );
    }
});
