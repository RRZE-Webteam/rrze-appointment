import { __ } from '@wordpress/i18n';
import {
    Button,
    PanelBody,
    SelectControl,
    TextControl,
    TextareaControl
} from '@wordpress/components';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import {
    formatDate,
    formatDateDisplay,
    parseDateString,
    parseTimeToMinutes,
    getCalendarDates,
    generateTimeSlots,
    groupSlotsByDate,
    expandRecurrence,
    renderGroupedSlotsAccordion
} from './utils';

function CalendarMultiSelect({ selectedDates, activeDate, onToggleDate }) {
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
        const dayDate = new Date(year, month, day);
        const dateString = formatDate(dayDate);
        const isSelected = selectedSet.has(dateString);
        const isPast = dateString < todayString;
        const isToday = dateString === todayString;
        const isActive = !!activeDate && dateString === activeDate;
        const dayOfWeek = dayDate.getDay();
        const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;

        const baseBackground = isPast ? '#f0f0f1' : (isWeekend ? '#f3f4f6' : '#fff');
        const selectedBackground = isSelected ? 'var(--wp-admin-theme-color, #007cba)' : baseBackground;
        const textColor = isPast ? '#8c8f94' : (isSelected ? '#fff' : '#1e1e1e');
        let borderColor = '#dcdcde';
        if (isToday) borderColor = '#2f7d32';
        if (isActive) borderColor = '#d63638';

        dayButtons.push(
            <button
                key={dateString}
                type="button"
                onClick={() => { if (!isPast) onToggleDate(dateString); }}
                aria-label={dateString}
                disabled={isPast}
                style={{
                    height: '30px',
                    border: `2px solid ${borderColor}`,
                    borderRadius: '4px',
                    cursor: isPast ? 'not-allowed' : 'pointer',
                    background: selectedBackground,
                    color: textColor,
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
                <Button variant="secondary" isSmall onClick={() => setViewDate(new Date(year, month - 1, 1))}>{'<'}</Button>
                <strong>{viewDate.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })}</strong>
                <Button variant="secondary" isSmall onClick={() => setViewDate(new Date(year, month + 1, 1))}>{'>'}</Button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0, 1fr))', gap: '4px' }}>
                {weekdayNames.map((name) => (
                    <div key={name} style={{ fontSize: '12px', textAlign: 'center', color: '#50575e' }}>{name}</div>
                ))}
                {dayButtons}
            </div>
        </div>
    );
}

function renderPreviewCalendar(slots, { onRemoveSlot, onAddSlot } = {}) {
    const groupedSlots = groupSlotsByDate(slots);
    const dates = Object.keys(groupedSlots).sort();
    if (dates.length === 0) return null;

    const weekdays = ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"];
    const firstDate = parseDateString(dates[0]);
    const lastDate = parseDateString(dates[dates.length - 1]);
    if (!firstDate || !lastDate) return null;

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
                                    if (isAvailable) classNames.push("is-available");
                                    if (isActive) classNames.push("is-active");

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
                        <div className="rrze-appointment__slot-item" key={slot.value}>
                            <button type="button" className="rrze-appointment__slot-button">{slot.timeRange}</button>
                            {onRemoveSlot && (
                                <button
                                    type="button"
                                    className="rrze-appointment__slot-delete"
                                    aria-label={`Uhrzeit ${slot.timeRange} löschen`}
                                    onClick={() => onRemoveSlot(slot)}
                                >
                                    ×
                                </button>
                            )}
                        </div>
                    ))}
                    {onAddSlot && (
                        <button
                            type="button"
                            className="rrze-appointment__slot-add"
                            aria-label={`Uhrzeit am ${formatDateDisplay(activeDate)} hinzufügen`}
                            onClick={() => onAddSlot(activeDate)}
                        >
                            +
                        </button>
                    )}
                </div>
            </fieldset>
        </Fragment>
    );
}

export default function Edit({ attributes, setAttributes }) {
    const {
        title,
        startTime,
        endTime,
        dateOverrides,
        duration,
        breakDuration,
        location,
        description,
        recurrence
    } = attributes;

    const rec = (recurrence && typeof recurrence === 'object') ? recurrence : {};
    const recFreq = rec.freq || '';
    const recUntil = rec.until || '';
    const recAnchor = rec.anchor || '';
    const recDates = Array.isArray(rec.dates) ? rec.dates : [];

    const calendarDates = getCalendarDates(attributes);
    const slots = generateTimeSlots(attributes);
    const blockProps = useBlockProps();
    const [activeDate, setActiveDate] = useState(calendarDates[0] || '');
    const [addSlotDate, setAddSlotDate] = useState(null);
    const [addSlotTime, setAddSlotTime] = useState('');
    const [addSlotEndTime, setAddSlotEndTime] = useState('');
    const [addSlotError, setAddSlotError] = useState('');

    useEffect(() => {
        if (!activeDate || !calendarDates.includes(activeDate)) {
            setActiveDate(calendarDates[0] || '');
        }
    }, [activeDate, calendarDates]);

    const firstDate = calendarDates[0] || '';
    const activeOverrides = (dateOverrides && typeof dateOverrides === 'object') ? dateOverrides : {};
    const activeOverride = activeDate ? (activeOverrides[activeDate] || {}) : {};
    const effectiveStartTime = activeOverride.startTime || startTime;
    const effectiveEndTime = activeOverride.endTime || endTime;
    const effectiveDuration = activeOverride.duration != null ? activeOverride.duration : duration;
    const effectiveBreakDuration = activeOverride.breakDuration != null ? activeOverride.breakDuration : breakDuration;
    const slotDuration = Number(effectiveDuration);
    const pauseMinutes = Number(effectiveBreakDuration);

    const applyRecurrence = (nextRec) => {
        if (!nextRec.freq || !activeDate) {
            // Wiederholung deaktiviert: recurrence-Daten aus selectedDates entfernen
            const nextDates = calendarDates.filter((d) => !recDates.includes(d) || d === recAnchor);
            setAttributes({
                recurrence: {},
                selectedDates: nextDates,
                startDate: nextDates[0] || '',
                endDate: nextDates[nextDates.length - 1] || '',
                useEndDate: nextDates.length > 1
            });
            return;
        }
        const anchor = nextRec.anchor || activeDate;
        const expanded = expandRecurrence({ ...nextRec, anchor }, anchor);
        // Manuell hinzugefügte Daten behalten (nicht Teil der vorherigen Recurrence)
        const manualDates = calendarDates.filter((d) => !recDates.includes(d));
        const nextDates = [...new Set([...manualDates, ...expanded])].sort();
        setAttributes({
            recurrence: { ...nextRec, anchor, dates: expanded },
            selectedDates: nextDates,
            startDate: nextDates[0] || '',
            endDate: nextDates[nextDates.length - 1] || '',
            useEndDate: nextDates.length > 1
        });
    };

    const handleRemoveSlot = (slot) => {
        if (!slot || !slot.date) return;
        const overridesNext = { ...activeOverrides };
        const currentOverride = overridesNext[slot.date] || {};
        const removedSlots = new Set(Array.isArray(currentOverride.removedSlots) ? currentOverride.removedSlots : []);
        const currentExtras = Array.isArray(currentOverride.extraSlots) ? currentOverride.extraSlots : [];
        let nextExtras = currentExtras;

        if (slot.isExtra) {
            nextExtras = currentExtras.filter((entry) => {
                const entryStart = entry.includes('|') ? entry.split('|')[0] : entry;
                return entryStart !== slot.startTime;
            });
        } else {
            removedSlots.add(slot.value);
        }

        const nextOverride = { ...currentOverride };
        if (removedSlots.size > 0) nextOverride.removedSlots = Array.from(removedSlots);
        else delete nextOverride.removedSlots;
        if (nextExtras.length > 0) nextOverride.extraSlots = nextExtras;
        else delete nextOverride.extraSlots;

        if (Object.keys(nextOverride).length === 0) delete overridesNext[slot.date];
        else overridesNext[slot.date] = nextOverride;

        setAttributes({ dateOverrides: overridesNext });
    };

    const handleOpenAddSlot = (date) => {
        setAddSlotDate(date);
        setAddSlotTime('');
        setAddSlotEndTime('');
        setAddSlotError('');
    };

    const handleConfirmAddSlot = () => {
        if (!addSlotDate || !addSlotTime || !addSlotEndTime) return;
        const newStartMinutes = parseTimeToMinutes(addSlotTime);
        const newEndMinutes = parseTimeToMinutes(addSlotEndTime);
        if (newStartMinutes === null || newEndMinutes === null) {
            setAddSlotError(__('Ungültige Uhrzeit.', 'rrze-appointment'));
            return;
        }
        if (newEndMinutes <= newStartMinutes) {
            setAddSlotError(__('Endzeit muss nach der Startzeit liegen.', 'rrze-appointment'));
            return;
        }
        if (newEndMinutes > 24 * 60) {
            setAddSlotError(__('Uhrzeit überschreitet Tagesende.', 'rrze-appointment'));
            return;
        }
        const dateSlots = slots.filter((s) => s.date === addSlotDate);
        const overlaps = dateSlots.some(
            (s) => newStartMinutes < s.endMinutes && newEndMinutes > s.startMinutes
        );
        if (overlaps) {
            setAddSlotError(__('Diese Zeitspanne ist bereits belegt.', 'rrze-appointment'));
            return;
        }
        const overridesNext = { ...activeOverrides };
        const currentOverride = overridesNext[addSlotDate] || {};
        const nextExtras = Array.isArray(currentOverride.extraSlots) ? [...currentOverride.extraSlots] : [];
        // Store as "HH:MM-HH:MM" to carry custom end time
        const slotKey = `${addSlotTime}|${addSlotEndTime}`;
        if (!nextExtras.includes(slotKey)) nextExtras.push(slotKey);
        overridesNext[addSlotDate] = { ...currentOverride, extraSlots: nextExtras };
        setAttributes({ dateOverrides: overridesNext });
        setAddSlotDate(null);
        setAddSlotTime('');
        setAddSlotEndTime('');
        setAddSlotError('');
    };

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
                        activeDate={activeDate}
                        onToggleDate={(selectedDate) => {
                            const dateSet = new Set(calendarDates);
                            const overridesNext = { ...((dateOverrides && typeof dateOverrides === 'object') ? dateOverrides : {}) };
                            const wasSelected = dateSet.has(selectedDate);
                            if (wasSelected) {
                                dateSet.delete(selectedDate);
                                delete overridesNext[selectedDate];
                            } else {
                                dateSet.add(selectedDate);
                            }
                            const nextDates = Array.from(dateSet).sort();
                            setAttributes({
                                selectedDates: nextDates,
                                startDate: nextDates[0] || '',
                                endDate: nextDates[nextDates.length - 1] || '',
                                useEndDate: nextDates.length > 1,
                                dateOverrides: overridesNext
                            });
                            if (wasSelected) {
                                if (activeDate === selectedDate) setActiveDate(nextDates[0] || '');
                            } else {
                                setActiveDate(selectedDate);
                            }
                        }}
                    />
                    <TextControl
                        label="Startzeit"
                        type="time"
                        step={300}
                        value={effectiveStartTime}
                        onChange={(value) => {
                            if (activeDate && activeDate !== firstDate) {
                                setAttributes({ dateOverrides: { ...activeOverrides, [activeDate]: { ...activeOverride, startTime: value } } });
                                return;
                            }
                            setAttributes({ startTime: value });
                        }}
                    />
                    <TextControl
                        label="Endzeit"
                        type="time"
                        step={300}
                        value={effectiveEndTime}
                        onChange={(value) => {
                            if (activeDate && activeDate !== firstDate) {
                                setAttributes({ dateOverrides: { ...activeOverrides, [activeDate]: { ...activeOverride, endTime: value } } });
                                return;
                            }
                            setAttributes({ endTime: value });
                        }}
                    />

                    <SelectControl
                        label="Dauer"
                        value={String(effectiveDuration)}
                        options={[
                            { label: '15 Minuten', value: '15' },
                            { label: '30 Minuten', value: '30' },
                            { label: '45 Minuten', value: '45' },
                            { label: '60 Minuten', value: '60' },
                            { label: '75 Minuten', value: '75' },
                            { label: '90 Minuten', value: '90' },
                            { label: '120 Minuten', value: '120' }
                        ]}
                        onChange={(value) => {
                            if (activeDate && activeDate !== firstDate) {
                                setAttributes({ dateOverrides: { ...activeOverrides, [activeDate]: { ...activeOverride, duration: Number(value) } } });
                                return;
                            }
                            setAttributes({ duration: Number(value) });
                        }}
                    />

                    <SelectControl
                        label="Pause"
                        value={String(effectiveBreakDuration)}
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
                        onChange={(value) => {
                            if (activeDate && activeDate !== firstDate) {
                                setAttributes({ dateOverrides: { ...activeOverrides, [activeDate]: { ...activeOverride, breakDuration: Number(value) } } });
                                return;
                            }
                            setAttributes({ breakDuration: Number(value) });
                        }}
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

                <PanelBody title={__('Wiederholen', 'rrze-appointment')} initialOpen={false}>
                    <p style={{ margin: '0 0 8px', color: '#50575e', fontSize: '12px' }}>
                        {activeDate
                            ? `${__('Gilt für', 'rrze-appointment')}: ${formatDateDisplay(activeDate)}`
                            : __('Bitte zuerst einen Tag auswählen.', 'rrze-appointment')
                        }
                    </p>
                    <SelectControl
                        label={__('Wiederholung', 'rrze-appointment')}
                        value={recFreq}
                        options={[
                            { label: __('Nicht wiederholen', 'rrze-appointment'), value: '' },
                            { label: __('Täglich', 'rrze-appointment'), value: 'daily' },
                            { label: __('Wöchentlich', 'rrze-appointment'), value: 'weekly' },
                            { label: __('Monatlich', 'rrze-appointment'), value: 'monthly' }
                        ]}
                        onChange={(value) => applyRecurrence({ ...rec, freq: value })}
                    />

                    {recFreq && (
                        <TextControl
                            label={__('Endet am', 'rrze-appointment')}
                            type="date"
                            value={recUntil}
                            onChange={(value) => applyRecurrence({ ...rec, until: value })}
                        />
                    )}
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
                                {renderPreviewCalendar(slots, { onRemoveSlot: handleRemoveSlot, onAddSlot: handleOpenAddSlot })}
                                {renderGroupedSlotsAccordion(slots, 'rrze_appointment_slot_preview', { onRemoveSlot: handleRemoveSlot, onAddSlot: handleOpenAddSlot })}
                            </Fragment>
                        ) : (
                            <p>Bitte mindestens einen Tag sowie Startzeit, Endzeit, Dauer und Pause setzen.</p>
                        )}
                        {addSlotDate && (
                            <div style={{
                                position: 'fixed', inset: 0,
                                background: 'rgba(0,0,0,0.5)',
                                display: 'flex', alignItems: 'center', justifyContent: 'center',
                                zIndex: 999999
                            }}>
                                <div style={{
                                    background: '#fff',
                                    borderRadius: '4px',
                                    padding: '24px',
                                    minWidth: '300px',
                                    boxShadow: '0 4px 24px rgba(0,0,0,0.2)'
                                }}>
                                    <p style={{ margin: '0 0 16px' }}>
                                        <strong>{__('Neue Uhrzeit für', 'rrze-appointment')} {formatDateDisplay(addSlotDate)}</strong>
                                    </p>
                                    <TextControl
                                        label={__('Startzeit', 'rrze-appointment')}
                                        type="time"
                                        step={300}
                                        value={addSlotTime}
                                        onChange={(value) => { setAddSlotTime(value); setAddSlotError(''); }}
                                    />
                                    <TextControl
                                        label={__('Endzeit', 'rrze-appointment')}
                                        type="time"
                                        step={300}
                                        value={addSlotEndTime}
                                        onChange={(value) => { setAddSlotEndTime(value); setAddSlotError(''); }}
                                    />
                                    {addSlotError && (
                                        <p style={{ color: '#d63638', margin: '-8px 0 8px' }}>{addSlotError}</p>
                                    )}
                                    <div style={{ display: 'flex', gap: '8px', marginTop: '8px' }}>
                                        <Button variant="primary" onClick={handleConfirmAddSlot}>
                                            {__('Hinzufügen', 'rrze-appointment')}
                                        </Button>
                                        <Button variant="secondary" onClick={() => { setAddSlotDate(null); setAddSlotError(''); }}>
                                            {__('Abbrechen', 'rrze-appointment')}
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </form>
                </div>
            </div>
        </Fragment>
    );
}
