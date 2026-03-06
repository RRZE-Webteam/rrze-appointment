import { registerBlockType } from '@wordpress/blocks';
import './style.css';
import {
    Button,
    PanelBody,
    SelectControl,
    TextControl,
    TextareaControl
} from '@wordpress/components';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Fragment, useMemo, useState } from '@wordpress/element';

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

function parseDateString(value) {
    if (!value || typeof value !== 'string') {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);
    if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) {
        return null;
    }

    return new Date(year, month - 1, day);
}

function normalizeDateList(values) {
    if (!Array.isArray(values)) {
        return [];
    }

    return [...new Set(values.map((value) => {
        if (typeof value === 'string') {
            return value.slice(0, 10);
        }

        if (value instanceof Date && !Number.isNaN(value.getTime())) {
            return formatDate(value);
        }

        return '';
    }).filter(Boolean))].sort();
}

function getDateRange(startDate, endDate) {
    if (!startDate || !endDate || endDate < startDate) {
        return [];
    }

    const fromDate = new Date(`${startDate}T00:00:00`);
    const toDate = new Date(`${endDate}T00:00:00`);
    if (Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime())) {
        return [];
    }

    const dates = [];
    const currentDate = new Date(fromDate);
    while (currentDate <= toDate) {
        dates.push(formatDate(currentDate));
        currentDate.setDate(currentDate.getDate() + 1);
        if (dates.length >= 366) {
            break;
        }
    }

    return dates;
}

function getCalendarDates(attributes) {
    const selectedDates = normalizeDateList(attributes.selectedDates);
    if (selectedDates.length > 0) {
        return selectedDates;
    }

    if (!attributes.startDate) {
        return [];
    }

    const selectedEndDate = (attributes.useEndDate && attributes.endDate) ? attributes.endDate : attributes.startDate;
    return getDateRange(attributes.startDate, selectedEndDate);
}

function generateTimeSlots(attributes) {
    const {
        startTime,
        endTime,
        duration,
        breakDuration
    } = attributes;

    const calendarDates = getCalendarDates(attributes);
    if (calendarDates.length === 0) {
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

    const slots = [];
    for (const dateString of calendarDates) {
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
            <div className="rrze-appointment__slot-grid">
            {dateSlots.map((slot) => (
                <label className="rrze-appointment__slot-option" key={slot.value}>
                    <input
                        className="rrze-appointment__slot-radio"
                        type="radio"
                        name={name}
                        value={slot.value}
                        required
                    />
                    <span className="rrze-appointment__slot-button">{slot.timeRange}</span>
                </label>
            ))}
            </div>
        </div>
    ));
}

function renderGroupedSlotsAccordion(slots, name) {
    const groups = groupSlotsByDate(slots);

    return (
        <details className="rrze-appointment__accordion rrze-appointment__slots-grouped" open>
            <summary>Alle Termine</summary>
            <div className="rrze-appointment__accordion-content">
                {Object.entries(groups).map(([date, dateSlots], index) => (
                    <details className="rrze-appointment__date-group" key={date} open={index === 0}>
                        <summary className="rrze-appointment__date-title">{date}</summary>
                        <div className="rrze-appointment__slot-grid" data-date={date}>
                            {dateSlots.map((slot) => (
                                <div className="rrze-appointment__slot-item" key={slot.value}>
                                    <input
                                        className="rrze-appointment__slot-radio"
                                        type="radio"
                                        name={name}
                                        value={slot.value}
                                        data-label={slot.timeRange}
                                        required
                                    />
                                    <button
                                        type="button"
                                        className="rrze-appointment__slot-button"
                                        data-slot-value={slot.value}
                                    >
                                        {slot.timeRange}
                                    </button>
                                </div>
                            ))}
                        </div>
                    </details>
                ))}
            </div>
        </details>
    );
}

function renderPreviewCalendar(slots) {
    const groupedSlots = groupSlotsByDate(slots);
    const dates = Object.keys(groupedSlots).sort();

    if (dates.length === 0) {
        return null;
    }

    const weekdays = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"];
    const firstDate = parseDateString(dates[0]);
    const lastDate = parseDateString(dates[dates.length - 1]);

    if (!firstDate || !lastDate) {
        return null;
    }

    const totalMonths = ((lastDate.getFullYear() - firstDate.getFullYear()) * 12) + (lastDate.getMonth() - firstDate.getMonth());
    const activeDate = dates[0];

    return (
        <Fragment>
            <div className="rrze-appointment__calendar">
                {Array.from({ length: totalMonths + 1 }).map((_, index) => {
                    const monthDate = new Date(firstDate.getFullYear(), firstDate.getMonth() + index, 1);
                    const year = monthDate.getFullYear();
                    const monthIndex = monthDate.getMonth();
                    const firstWeekdayIndex = (new Date(year, monthIndex, 1).getDay() + 6) % 7;
                    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();

                    return (
                        <div className="rrze-appointment__calendar-month" key={String(year) + "-" + String(monthIndex)}>
                            <div className="rrze-appointment__calendar-title">
                                {monthDate.toLocaleDateString("de-DE", { month: "long", year: "numeric" })}
                            </div>
                            <div className="rrze-appointment__calendar-grid">
                                {weekdays.map((weekday) => (
                                    <div className="rrze-appointment__weekday" key={weekday}>{weekday}</div>
                                ))}
                                {Array.from({ length: firstWeekdayIndex }).map((__, emptyIndex) => (
                                    <div className="rrze-appointment__calendar-empty" key={"empty-" + String(year) + "-" + String(monthIndex) + "-" + String(emptyIndex)} />
                                ))}
                                {Array.from({ length: daysInMonth }).map((__, dayIndex) => {
                                    const day = dayIndex + 1;
                                    const dateString = String(year) + "-" + String(monthIndex + 1).padStart(2, "0") + "-" + String(day).padStart(2, "0");
                                    const isAvailable = dates.includes(dateString);
                                    const isActive = dateString === activeDate;
                                    const classNames = ["rrze-appointment__calendar-day"];

                                    if (isAvailable) {
                                        classNames.push("is-available");
                                    }

                                    if (isActive) {
                                        classNames.push("is-active");
                                    }

                                    return (
                                        <button
                                            key={dateString}
                                            type="button"
                                            className={classNames.join(" ")}
                                            disabled={!isAvailable}
                                        >
                                            {day}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>

            <fieldset className="rrze-appointment__day-slots">
                <legend>Uhrzeiten am ausgewählten Tag</legend>
                <div className="rrze-appointment__day-slots-list rrze-appointment__slot-grid">
                    {groupedSlots[activeDate].map((slot) => (
                        <span className="rrze-appointment__slot-button" key={slot.value}>
                            {slot.timeRange}
                        </span>
                    ))}
                </div>
            </fieldset>
        </Fragment>
    );
}

function CalendarMultiSelect({ selectedDates, onToggleDate }) {
    const today = new Date();
    const todayString = formatDate(today);
    const selectedSet = useMemo(() => new Set(selectedDates), [selectedDates]);
    const latestSelected = selectedDates[selectedDates.length - 1];
    const latestSelectedDate = parseDateString(latestSelected);
    const [viewDate, setViewDate] = useState(() => new Date(
        latestSelectedDate ? latestSelectedDate.getFullYear() : today.getFullYear(),
        latestSelectedDate ? latestSelectedDate.getMonth() : today.getMonth(),
        1
    ));

    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const startsOnMonday = (new Date(year, month, 1).getDay() + 6) % 7;
    const weekdayNames = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    const dayButtons = [];
    for (let i = 0; i < startsOnMonday; i++) {
        dayButtons.push(<div key={`empty-${i}`} />);
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const dateString = formatDate(new Date(year, month, day));
        const isSelected = selectedSet.has(dateString);
        const isPast = dateString < todayString;

        dayButtons.push(
            <button
                key={dateString}
                type="button"
                onClick={() => {
                    if (!isPast) {
                        onToggleDate(dateString);
                    }
                }}
                aria-label={dateString}
                disabled={isPast}
                style={{
                    height: '30px',
                    border: '1px solid #dcdcde',
                    borderRadius: '4px',
                    cursor: isPast ? 'not-allowed' : 'pointer',
                    background: isPast
                        ? '#f0f0f1'
                        : (isSelected ? 'var(--wp-admin-theme-color, #007cba)' : '#fff'),
                    color: isPast ? '#8c8f94' : (isSelected ? '#fff' : '#1e1e1e'),
                    fontWeight: isSelected ? 600 : 400
                }}
            >
                {day}
            </button>
        );
    }

    return (
        <div style={{ marginBottom: '12px' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '8px' }}>
                <Button
                    variant="secondary"
                    isSmall
                    onClick={() => setViewDate(new Date(year, month - 1, 1))}
                >
                    {'<'}
                </Button>
                <strong>
                    {viewDate.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })}
                </strong>
                <Button
                    variant="secondary"
                    isSmall
                    onClick={() => setViewDate(new Date(year, month + 1, 1))}
                >
                    {'>'}
                </Button>
            </div>
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
                    gap: '4px'
                }}
            >
                {weekdayNames.map((name) => (
                    <div key={name} style={{ fontSize: '12px', textAlign: 'center', color: '#50575e' }}>
                        {name}
                    </div>
                ))}
                {dayButtons}
            </div>
        </div>
    );
}

registerBlockType('rrze/appointment', {
    edit({ attributes, setAttributes }) {
        const {
            title,
            startTime,
            endTime,
            duration,
            breakDuration,
            location,
            description
        } = attributes;

        const calendarDates = getCalendarDates(attributes);
        const slots = generateTimeSlots(attributes);
        const blockProps = useBlockProps();

        return (
            <Fragment>
                <InspectorControls>
                    <PanelBody title="Termin-Einstellungen" initialOpen={true}>
                        <TextControl
                            label="Titel"
                            value={title}
                            onChange={(value) => setAttributes({ title: value })}
                        />

                        <p><strong>Kalender-Ansicht</strong></p>
                        <p>Ein Klick auf ein Datum fügt es hinzu oder entfernt es wieder.</p>
                        <CalendarMultiSelect
                            selectedDates={calendarDates}
                            onToggleDate={(selectedDate) => {
                                const dateSet = new Set(calendarDates);
                                if (dateSet.has(selectedDate)) {
                                    dateSet.delete(selectedDate);
                                } else {
                                    dateSet.add(selectedDate);
                                }

                                const nextDates = Array.from(dateSet).sort();
                                setAttributes({
                                    selectedDates: nextDates,
                                    startDate: nextDates[0] || '',
                                    endDate: nextDates[nextDates.length - 1] || '',
                                    useEndDate: nextDates.length > 1
                                });
                            }}
                        />
                        <TextControl
                            label="Startzeit"
                            type="time"
                            step={300}
                            value={startTime}
                            onChange={(value) => setAttributes({ startTime: value })}
                        />
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

                <div {...blockProps}>
                    <div className="rrze-appointment-block">
                        <h3>{title || 'Termin-Titel'}</h3>
                        {description && <p>{description}</p>}
                        {location && <p><strong>Ort:</strong> {location}</p>}

                        <form className="rrze-appointment__form">
                            {slots.length > 0 ? (
                                <Fragment>
                                    {renderPreviewCalendar(slots)}
                                    {renderGroupedSlotsAccordion(slots, 'rrze_appointment_slot_preview')}
                                </Fragment>
                            ) : (
                                <p>Bitte mindestens einen Tag sowie Startzeit, Endzeit, Dauer und Pause setzen.</p>
                            )}
                        </form>
                    </div>
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

                        {renderGroupedSlotsAccordion(slots, 'rrze_appointment_slot')}

                        <div className="rrze-appointment__selected-info is-hidden">
                            <p className="rrze-appointment__selected-text" />
                            <button type="button" className="rrze-appointment__book-button">
                                Buchen
                            </button>
                            <p className="rrze-appointment__book-status" />
                        </div>
                    </Fragment>
                ) : (
                    <p className="rrze-appointment__missing-slot">
                        Keine Timeslots verfügbar.
                    </p>
                )}
            </form>
        );
    }
});
