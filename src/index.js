import { registerBlockType } from '@wordpress/blocks';
import {
    DatePicker,
    PanelBody,
    SelectControl,
    TextControl,
    TextareaControl,
    ToggleControl
} from '@wordpress/components';
import { InspectorControls } from '@wordpress/block-editor';
import { Fragment } from '@wordpress/element';

function normalizePickerDate(value) {
    if (!value) {
        return '';
    }

    if (typeof value === 'string') {
        return value.slice(0, 10);
    }

    if (value instanceof Date && !Number.isNaN(value.getTime())) {
        return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;
    }

    return '';
}

function parseTimeToMinutes(time) {
    if (!time || typeof time !== 'string') {
        return null;
    }

    const [hours, minutes] = time.split(':').map(Number);
    if (!Number.isInteger(hours) || !Number.isInteger(minutes)) {
        return null;
    }

    if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) {
        return null;
    }

    return (hours * 60) + minutes;
}

function minutesToTime(totalMinutes) {
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

function formatDate(dateObj) {
    return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`;
}

function generateTimeSlots(attributes) {
    const {
        startDate,
        endDate,
        useEndDate,
        startTime,
        endTime,
        duration,
        breakDuration
    } = attributes;

    if (!startDate) {
        return [];
    }

    const selectedEndDate = (useEndDate && endDate) ? endDate : startDate;
    if (selectedEndDate < startDate) {
        return [];
    }

    const startMinutes = parseTimeToMinutes(startTime);
    const endMinutes = parseTimeToMinutes(endTime);
    const slotDuration = Number(duration);
    const pauseMinutes = Number(breakDuration);

    if (
        startMinutes === null
        || endMinutes === null
        || endMinutes <= startMinutes
        || !Number.isFinite(slotDuration)
        || slotDuration <= 0
        || slotDuration % 15 !== 0
        || !Number.isFinite(pauseMinutes)
        || pauseMinutes < 0
        || pauseMinutes > 55
        || pauseMinutes % 5 !== 0
    ) {
        return [];
    }

    const fromDate = new Date(`${startDate}T00:00:00`);
    const toDate = new Date(`${selectedEndDate}T00:00:00`);
    if (Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime())) {
        return [];
    }

    const slots = [];
    const currentDate = new Date(fromDate);

    while (currentDate <= toDate) {
        const dateString = formatDate(currentDate);
        let slotStart = startMinutes;

        while (slotStart + slotDuration <= endMinutes) {
            const slotEnd = slotStart + slotDuration;
            const startLabel = minutesToTime(slotStart);
            const endLabel = minutesToTime(slotEnd);

            slots.push({
                date: dateString,
                timeRange: `${startLabel} - ${endLabel}`,
                value: `${dateString} ${startLabel}-${endLabel}`,
                label: `${dateString}, ${startLabel} - ${endLabel}`
            });

            slotStart += slotDuration + pauseMinutes;
        }

        currentDate.setDate(currentDate.getDate() + 1);

        if (slots.length >= 1000) {
            break;
        }
    }

    return slots;
}

function groupSlotsByDate(slots) {
    return slots.reduce((acc, slot) => {
        if (!acc[slot.date]) {
            acc[slot.date] = [];
        }

        acc[slot.date].push(slot);
        return acc;
    }, {});
}

function renderGroupedSlots(slots, name) {
    const groups = groupSlotsByDate(slots);

    return Object.entries(groups).map(([date, dateSlots]) => (
        <div className="rrze-appointment__date-group" key={date}>
            <h4 className="rrze-appointment__date-title">{date}</h4>
            {dateSlots.map((slot) => (
                <label className="rrze-appointment__slot-option" key={slot.value}>
                    <input
                        type="radio"
                        name={name}
                        value={slot.value}
                        required
                    />
                    <span>{slot.timeRange}</span>
                </label>
            ))}
        </div>
    ));
}

registerBlockType('rrze/appointment', {
    edit({ attributes, setAttributes }) {
        const {
            title,
            startDate,
            useEndDate,
            endDate,
            startTime,
            endTime,
            duration,
            breakDuration,
            location,
            description
        } = attributes;

        const slots = generateTimeSlots(attributes);

        return (
            <Fragment>
                <InspectorControls>
                    <PanelBody title="Termin-Einstellungen" initialOpen={true}>
                        <TextControl
                            label="Titel"
                            value={title}
                            onChange={(value) => setAttributes({ title: value })}
                        />

                        <p><strong>Start</strong></p>
                        <DatePicker
                            currentDate={startDate || undefined}
                            onChange={(value) => setAttributes({ startDate: normalizePickerDate(value) })}
                        />
                        <TextControl
                            label="Startzeit"
                            type="time"
                            step={300}
                            value={startTime}
                            onChange={(value) => setAttributes({ startTime: value })}
                        />

                        <ToggleControl
                            label="Enddatum verwenden (optional)"
                            checked={useEndDate}
                            onChange={(value) => setAttributes({ useEndDate: value })}
                        />

                        <p><strong>Ende</strong></p>
                        {useEndDate && (
                            <DatePicker
                                currentDate={endDate || startDate || undefined}
                                onChange={(value) => setAttributes({ endDate: normalizePickerDate(value) })}
                            />
                        )}
                        <TextControl
                            label="Endzeit"
                            type="time"
                            step={300}
                            value={endTime}
                            onChange={(value) => setAttributes({ endTime: value })}
                        />

                        <SelectControl
                            label="Dauer"
                            value={String(duration)}
                            options={[
                                { label: '15 Minuten', value: '15' },
                                { label: '30 Minuten', value: '30' },
                                { label: '45 Minuten', value: '45' },
                                { label: '60 Minuten', value: '60' },
                                { label: '75 Minuten', value: '75' },
                                { label: '90 Minuten', value: '90' },
                                { label: '120 Minuten', value: '120' }
                            ]}
                            onChange={(value) => setAttributes({ duration: Number(value) })}
                        />

                        <SelectControl
                            label="Pause"
                            value={String(breakDuration)}
                            options={[
                                { label: '0 Minuten', value: '0' },
                                { label: '5 Minuten', value: '5' },
                                { label: '10 Minuten', value: '10' },
                                { label: '15 Minuten', value: '15' },
                                { label: '20 Minuten', value: '20' },
                                { label: '25 Minuten', value: '25' },
                                { label: '30 Minuten', value: '30' },
                                { label: '35 Minuten', value: '35' },
                                { label: '40 Minuten', value: '40' },
                                { label: '45 Minuten', value: '45' },
                                { label: '50 Minuten', value: '50' },
                                { label: '55 Minuten', value: '55' }
                            ]}
                            onChange={(value) => setAttributes({ breakDuration: Number(value) })}
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
                        <fieldset className="rrze-appointment__slots-grouped">
                            <legend>Alle Termine (gruppiert nach Datum)</legend>
                            {slots.length > 0 ? renderGroupedSlots(slots, 'rrze_appointment_slot_preview') : (
                                <p>Bitte Start/Ende, Dauer und Pause korrekt setzen.</p>
                            )}
                        </fieldset>
                    </form>
                </div>
            </Fragment>
        );
    },

    save({ attributes }) {
        const { title, location, description } = attributes;
        const slots = generateTimeSlots(attributes);

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

                {slots.length > 0 ? (
                    <Fragment>
                        <div className="rrze-appointment__calendar" />

                        <fieldset className="rrze-appointment__day-slots is-hidden">
                            <legend>Uhrzeiten am ausgewählten Tag</legend>
                            <div className="rrze-appointment__day-slots-list" />
                        </fieldset>

                        <fieldset className="rrze-appointment__slots-grouped">
                            <legend>Alle Termine (gruppiert nach Datum)</legend>
                            {renderGroupedSlots(slots, 'rrze_appointment_slot')}
                        </fieldset>
                    </Fragment>
                ) : (
                    <p className="rrze-appointment__missing-slot">
                        Keine Timeslots verfügbar.
                    </p>
                )}

                <button type="submit" className="rrze-appointment__submit" disabled={slots.length === 0}>
                    Termin buchen
                </button>
            </form>
        );
    }
});
