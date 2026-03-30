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
import apiFetch from '@wordpress/api-fetch';
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

        dayButtons.push(
            <button
                key={dateString}
                type="button"
                onClick={() => { if (!isPast) onToggleDate(dateString); }}
                aria-label={dateString}
                disabled={isPast}
                className={[
                    'rrze-appointment__calendar-day',
                    isPast ? 'is-past' : '',
                    isSelected ? 'is-available' : '',
                    isToday ? 'is-today' : '',
                    isActive ? 'is-active' : '',
                    isWeekend ? 'is-weekend' : '',
                ].filter(Boolean).join(' ')}
            >
                {day}
            </button>
        );
    }

    return (
        <div className="rrze-appointment-block__calendar">
            <div className="rrze-appointment-block__calendar-header">
                <Button variant="secondary" isSmall onClick={() => setViewDate(new Date(year, month - 1, 1))}>{'<'}</Button>
                <strong>{viewDate.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })}</strong>
                <Button variant="secondary" isSmall onClick={() => setViewDate(new Date(year, month + 1, 1))}>{'>'}</Button>
            </div>
            <div className="rrze-appointment-block__calendar-grid">
                {weekdayNames.map((name) => (
                    <div key={name} className="rrze-appointment-block__calendar-weekday">{name}</div>
                ))}
                {dayButtons}
            </div>
        </div>
    );
}


function PreviewCalendar({ slots, onRemoveSlot, onAddSlot, activeDate, setActiveDate }) {
    const groupedSlots = groupSlotsByDate(slots);
    const dates = Object.keys(groupedSlots).sort();
    if (dates.length === 0) return null;

    const firstDate = parseDateString(dates[0]);
    if (!firstDate) return null;

    const [viewDate, setViewDate] = useState(() => new Date(firstDate.getFullYear(), firstDate.getMonth(), 1));

    useEffect(() => {
        if (!activeDate) return;
        const d = parseDateString(activeDate);
        if (!d) return;
        setViewDate((prev) => {
            if (prev.getFullYear() === d.getFullYear() && prev.getMonth() === d.getMonth()) return prev;
            return new Date(d.getFullYear(), d.getMonth(), 1);
        });
    }, [activeDate]);

    const year = viewDate.getFullYear();
    const monthIndex = viewDate.getMonth();
    const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
    const firstWeekdayIndex = (new Date(year, monthIndex, 1).getDay() + 6) % 7;
    const weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    const activeDateSlots = activeDate ? (groupedSlots[activeDate] || []) : [];

    return (
        <Fragment>
            <div className="rrze-appointment__calendar">
                <div className="rrze-appointment__calendar-month">
                    <div className="rrze-appointment__calendar-title">
                        <button type="button" onClick={() => setViewDate(new Date(year, monthIndex - 1, 1))} className="rrze-appointment__calendar-nav">{'‹'}</button>
                        <span>{viewDate.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' })}</span>
                        <button type="button" onClick={() => setViewDate(new Date(year, monthIndex + 1, 1))} className="rrze-appointment__calendar-nav">{'›'}</button>
                    </div>
                    <div className="rrze-appointment__calendar-grid">
                        {weekdays.map((w) => (
                            <div className="rrze-appointment__weekday" key={w}>{w}</div>
                        ))}
                        {Array.from({ length: firstWeekdayIndex }).map((_, i) => (
                            <div className="rrze-appointment__calendar-empty" key={`empty-${i}`} />
                        ))}
                        {Array.from({ length: daysInMonth }).map((_, dayIndex) => {
                            const day = dayIndex + 1;
                            const dateString = `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                            const today = new Date();
                            const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
                            const dayOfWeek = new Date(year, monthIndex, day).getDay();
                            const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
                            const isPast = dateString < todayStr;
                            const isToday = dateString === todayStr;
                            const isAvailable = dates.includes(dateString);
                            const isActive = dateString === activeDate;
                            const classNames = ['rrze-appointment__calendar-day'];
                            if (isPast || isWeekend) classNames.push('is-past');
                            if (isToday) classNames.push('is-today');
                            if (isAvailable) classNames.push('is-available');
                            if (isActive) classNames.push('is-active');
                            return (
                                <button
                                    key={dateString}
                                    type="button"
                                    className={classNames.join(' ')}
                                    disabled={!isAvailable}
                                    onClick={() => isAvailable && setActiveDate(dateString)}
                                >
                                    {day}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            {activeDate && activeDateSlots.length > 0 && (
                <fieldset className="rrze-appointment__day-slots">
                    <legend>Uhrzeiten am {formatDateDisplay(activeDate)}</legend>
                    <div className="rrze-appointment__day-slots-list rrze-appointment__slot-grid">
                        {activeDateSlots.map((slot) => (
                            <div className="rrze-appointment__slot-item" key={slot.value}>
                                <button type="button" className="rrze-appointment__slot-button">{slot.timeRange}</button>
                                {onRemoveSlot && (
                                    <button
                                        type="button"
                                        className="rrze-appointment__slot-delete"
                                        aria-label={`Uhrzeit ${slot.timeRange} löschen`}
                                        onClick={() => onRemoveSlot(slot)}
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><line x1="5" y1="5" x2="19" y2="19" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" /><line x1="19" y1="5" x2="5" y2="19" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" /></svg>
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
            )}
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
        recurrence,
        personId,
        personName,
        personEmail,
        tplId,
        locationUrl
    } = attributes;

    const [mailTemplates, setMailTemplates] = useState([]);
    useEffect(() => {
        apiFetch({ path: '/wp/v2/rrze-mail-templates?per_page=100&status=publish' })
            .then((posts) => setMailTemplates(posts.map((p) => ({ value: p.id, label: p.title.rendered }))))
            .catch(() => { });
    }, []);


    const [faudirResponse] = useState(() => window.rrze_appointment?.persons || { error: true, message: 'Keine Personen-Daten verfügbar.', data: [] });

    const faudirPersons = !faudirResponse?.error && Array.isArray(faudirResponse?.data) ? faudirResponse.data : [];
    const faudirError = faudirResponse?.error ?? false;
    const faudirMessage = faudirResponse?.message || '';
    const selectedPerson = faudirPersons.find((p) => p.id === personId) || null;
    console.log('office hours persons:', faudirPersons.filter(p => p.hoursType === 'office').map(p => p.label));
    console.log('consultation hours persons:', faudirPersons.filter(p => p.hoursType === 'consultation').map(p => p.label));



    const [hoursOverlay, setHoursOverlay] = useState(null); // { person, type: 'consultation'|'office' }

    const applyConsultationHours = (person) => {
        const hours = person.consultationHours || [];
        if (!hours.length) return;

        // weekday: 0=So,1=Mo,...,6=Sa — wir wollen die nächsten 8 Wochen ab heute
        const today = new Date();
        const todayStr = formatDate(today);
        const dates = [];
        for (let i = 1; i <= 56; i++) {
            const d = new Date(today);
            d.setDate(today.getDate() + i);
            const jsDay = d.getDay(); // 0=So,1=Mo,...
            if (hours.some((h) => h.weekday === jsDay)) {
                dates.push(formatDate(d));
            }
        }
        if (!dates.length) return;

        // startTime/endTime aus erstem Eintrag
        const firstHour = hours[0];
        const newStart = firstHour.from || '09:00';
        const newEnd   = firstHour.to   || '17:00';

        setAttributes({
            selectedDates: dates,
            startDate:     dates[0],
            endDate:       dates[dates.length - 1],
            useEndDate:    true,
            startTime:     newStart,
            endTime:       newEnd,
            useConsultationHours: true,
        });
        setActiveDate(dates[0]);
    };

    const hasConsultationHours = selectedPerson?.consultationHours?.length > 0;
    const derivedTitle = selectedPerson
        ? `Sprechstunde von ${[selectedPerson.honorificPrefix, selectedPerson.givenName, selectedPerson.familyName].filter(Boolean).join(' ')}`
        : title;

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
                <PanelBody title={__('Mail-Vorlage', 'rrze-appointment')} initialOpen={false}>
                    <SelectControl
                        label={__('Vorlage', 'rrze-appointment')}
                        value={String(tplId || 0)}
                        options={[
                            { label: __('— Standard —', 'rrze-appointment'), value: '0' },
                            ...mailTemplates.map((t) => ({ label: t.label, value: String(t.value) }))
                        ]}
                        onChange={(v) => setAttributes({ tplId: Number(v) })}
                    />
                </PanelBody>
                {faudirError && (
                    <PanelBody title={__('Personen-Einstellungen', 'rrze-appointment')} initialOpen={true}>
                        <p className="rrze-appointment-block__person-error">{faudirMessage}</p>
                    </PanelBody>
                )}

                {!faudirError && faudirPersons.length > 0 && (
                    <PanelBody title={__('Personen-Einstellungen', 'rrze-appointment')} initialOpen={true}>
                        <SelectControl
                            label={__('Person', 'rrze-appointment')}
                            value={String(personId || 0)}
                            options={[
                                { label: __('— keine —', 'rrze-appointment'), value: '0' },
                                ...[...faudirPersons]
                                    .filter((p) => p.familyName || p.givenName || p.label)
                                    .map((p) => ({
                                        label: [p.familyName, p.honorificPrefix ? `(${p.honorificPrefix})` : null, p.givenName ? `${p.givenName}` : null].filter(Boolean).join(', ') || p.label,
                                        value: String(p.id)
                                    }))
                                    .sort((a, b) => a.label.localeCompare(b.label, 'de'))
                            ]}
                            onChange={(value) => {
                                const pid = Number(value);
                                const person = faudirPersons.find((p) => p.id === pid) || null;
                                const newTitle = person
                                    ? `Sprechstunde von ${[person.honorificPrefix, person.givenName, person.familyName].filter(Boolean).join(' ')}`
                                    : '';
                                setAttributes({ personId: pid, title: newTitle, personName: person ? [person.honorificPrefix, person.givenName, person.familyName].filter(Boolean).join(' ') : '', personEmail: person?.email || '', location: person?.location || '', locationUrl: person?.locationUrl || '', useConsultationHours: false });
                                if (person && person.consultationHours?.length > 0) {
                                    setHoursOverlay({ person, type: person.hoursType === 'office' ? 'office' : 'consultation' });
                                }
                            }}
                        />
                        <TextControl
                            label={__('Name', 'rrze-appointment')}
                            value={personName}
                            onChange={(value) => setAttributes({ personName: value })}
                        />
                        <TextControl
                            label={__('E-Mail', 'rrze-appointment')}
                            value={personEmail}
                            onChange={(value) => setAttributes({ personEmail: value })}
                        />
                        <TextControl
                            label={__('Karte (URL)', 'rrze-appointment')}
                            value={locationUrl}
                            onChange={(value) => setAttributes({ locationUrl: value })}
                        />
                    </PanelBody>
                )}
                <PanelBody title="Termin-Einstellungen" initialOpen={true}>
                    <TextControl
                        label="title"
                        value={derivedTitle}
                        onChange={(value) => setAttributes({ title: value, personId: 0 })}
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
                    <p className="rrze-appointment-block__recurrence-hint">
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
                    <h3>{derivedTitle || 'Termin-title'}</h3>
                    {description && <p>{description}</p>}
                    {location && <p><strong>Ort:</strong> {location}{locationUrl && <> (<a href={locationUrl} target="_blank" rel="noopener noreferrer">Auf der Karte ansehen</a>)</>}</p>}

                    <form className="rrze-appointment__form">
                        {slots.length > 0 ? (
                            <Fragment>
                                <PreviewCalendar slots={slots} onRemoveSlot={handleRemoveSlot} onAddSlot={handleOpenAddSlot} activeDate={activeDate} setActiveDate={setActiveDate} />
                                {renderGroupedSlotsAccordion(slots, 'rrze_appointment_slot_preview', { onRemoveSlot: handleRemoveSlot, onAddSlot: handleOpenAddSlot })}
                            </Fragment>
                        ) : (
                            <p>Bitte mindestens einen Tag sowie Startzeit, Endzeit, Dauer und Pause setzen.</p>
                        )}
                        {addSlotDate && (
                            <div className="rrze-appointment-block__add-slot-overlay">
                                <div className="rrze-appointment-block__add-slot-overlay-box">
                                    <p className="rrze-appointment-block__add-slot-overlay-title">
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
                                        <p className="rrze-appointment-block__add-slot-overlay-error">{addSlotError}</p>
                                    )}
                                    <div className="rrze-appointment-block__add-slot-overlay-actions">
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

            {hoursOverlay && (
                <div className="rrze-appointment-block__hours-overlay">
                    <div className="rrze-appointment-block__hours-overlay-box">
                        <p className="rrze-appointment-block__hours-overlay-text">
                            {hoursOverlay.type === 'consultation'
                                ? __('Sprechstunden von FAUdir gefunden. Sollen die Termine entsprechend erstellt werden?', 'rrze-appointment')
                                : __('Sprechstunden in FAUdir nicht gefunden, aber Bürozeiten. Sollen die Termine daraus entsprechend erstellt werden?', 'rrze-appointment')
                            }
                        </p>
                        <div className="rrze-appointment-block__hours-overlay-actions">
                            <Button variant="primary" onClick={() => { applyConsultationHours(hoursOverlay.person); setHoursOverlay(null); }}>
                                {__('Ja', 'rrze-appointment')}
                            </Button>
                            <Button variant="secondary" onClick={() => setHoursOverlay(null)}>
                                {__('Nein', 'rrze-appointment')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </Fragment>
    );
}