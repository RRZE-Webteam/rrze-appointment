import { registerBlockType } from '@wordpress/blocks';
import { 
    TextControl, 
    TextareaControl,
    DateTimePicker,
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
                    <h3>{title || "Termin-Titel"}</h3>
                    <p><strong>Datum:</strong> {date}</p>
                    <p><strong>Uhrzeit:</strong> {time}</p>
                    <p><strong>Ort:</strong> {location}</p>
                    <p>{description}</p>
                </div>
            </Fragment>
        );
    },

    save({ attributes }) {
        const { title, date, time, location, description } = attributes;

        return (
            <div className="rrze-appointment">
                <h3 className="rrze-appointment__title">{title}</h3>
                <p className="rrze-appointment__meta">
                    <span className="rrze-appointment__date">{date}</span>
                    {time && <> | <span className="rrze-appointment__time">{time}</span></>}
                </p>
                {location && (
                    <p className="rrze-appointment__location">{location}</p>
                )}
                {description && (
                    <div className="rrze-appointment__description">
                        {description}
                    </div>
                )}
            </div>
        );
    }
});