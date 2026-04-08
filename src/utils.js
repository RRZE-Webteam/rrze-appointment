import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export function parseTimeToMinutes(time) {
    if (!time || typeof time !== 'string') return null;
    const [hours, minutes] = time.split(':').map(Number);
    if (!Number.isInteger(hours) || !Number.isInteger(minutes)) return null;
    if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return null;
    return (hours * 60) + minutes;
}

export function minutesToTime(totalMinutes) {
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

export function formatDate(dateObj) {
    return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`;
}

export function parseDateString(value) {
    if (!value || typeof value !== 'string') return null;
    const [year, month, day] = value.split('-').map(Number);
    if (!Number.isInteger(year) || !Number.isInteger(month) || !Number.isInteger(day)) return null;
    return new Date(year, month - 1, day);
}

export function formatDateDisplay(dateString) {
    const dateObj = parseDateString(dateString);
    if (!dateObj) return dateString;
    return dateObj.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function normalizeDateList(values) {
    if (!Array.isArray(values)) return [];
    return [...new Set(values.map((value) => {
        if (typeof value === 'string') return value.slice(0, 10);
        if (value instanceof Date && !Number.isNaN(value.getTime())) return formatDate(value);
        return '';
    }).filter(Boolean))].sort();
}

export function getDateRange(startDate, endDate) {
    if (!startDate || !endDate || endDate < startDate) return [];
    const fromDate = new Date(`${startDate}T00:00:00`);
    const toDate = new Date(`${endDate}T00:00:00`);
    if (Number.isNaN(fromDate.getTime()) || Number.isNaN(toDate.getTime())) return [];
    const dates = [];
    const currentDate = new Date(fromDate);
    while (currentDate <= toDate) {
        dates.push(formatDate(currentDate));
        currentDate.setDate(currentDate.getDate() + 1);
        if (dates.length >= 366) break;
    }
    return dates;
}

export function getCalendarDates(attributes) {
    const selectedDates = normalizeDateList(attributes.selectedDates);
    if (selectedDates.length > 0) return selectedDates;
    if (!attributes.startDate) return [];
    const selectedEndDate = (attributes.useEndDate && attributes.endDate) ? attributes.endDate : attributes.startDate;
    return getDateRange(attributes.startDate, selectedEndDate);
}

export function generateTimeSlots(attributes) {
    const { startTime, endTime, dateOverrides, duration, breakDuration } = attributes;
    const calendarDates = getCalendarDates(attributes);
    if (calendarDates.length === 0) return [];

    const globalDuration = Number(duration);
    const globalPause = Number(breakDuration);

    if (
        !Number.isFinite(globalDuration) || globalDuration <= 0 || globalDuration % 15 !== 0 ||
        !Number.isFinite(globalPause) || globalPause < 0 || globalPause > 55 || globalPause % 5 !== 0
    ) return [];

    const overrides = (dateOverrides && typeof dateOverrides === 'object') ? dateOverrides : {};
    const slots = [];

    for (const dateString of calendarDates) {
        const override = overrides[dateString] || {};
        const slotDuration = override.duration != null ? Number(override.duration) : globalDuration;
        const pauseMinutes = override.breakDuration != null ? Number(override.breakDuration) : globalPause;
        const startMinutes = parseTimeToMinutes(override.startTime || startTime);
        const endMinutes = parseTimeToMinutes(override.endTime || endTime);
        if (startMinutes === null || endMinutes === null || endMinutes <= startMinutes) continue;

        const removedSlots = new Set(Array.isArray(override.removedSlots) ? override.removedSlots : []);
        const extraSlots = Array.isArray(override.extraSlots) ? override.extraSlots : [];
        const dateSlots = [];
        const slotMap = new Map();

        const addSlot = (slot) => {
            if (removedSlots.has(slot.value) || slotMap.has(slot.value)) return;
            slotMap.set(slot.value, slot);
            dateSlots.push(slot);
        };

        let slotStart = startMinutes;
        while (slotStart + slotDuration <= endMinutes) {
            const slotEnd = slotStart + slotDuration;
            const startLabel = minutesToTime(slotStart);
            const endLabel = minutesToTime(slotEnd);
            addSlot({
                date: dateString,
                startTime: startLabel,
                endTime: endLabel,
                startMinutes: slotStart,
                endMinutes: slotEnd,
                timeRange: `${startLabel} - ${endLabel}`,
                value: `${dateString} ${startLabel}-${endLabel}`,
                label: `${formatDateDisplay(dateString)} ${startLabel}`,
                isExtra: false
            });
            slotStart += slotDuration + pauseMinutes;
        }

        extraSlots.forEach((extraEntry) => {
            const hasPipe = typeof extraEntry === 'string' && extraEntry.includes('|');
            const extraStart = hasPipe ? extraEntry.split('|')[0] : extraEntry;
            const extraEnd = hasPipe ? extraEntry.split('|')[1] : null;
            const extraStartMinutes = parseTimeToMinutes(extraStart);
            if (extraStartMinutes === null) return;
            const extraEndMinutes = extraEnd
                ? parseTimeToMinutes(extraEnd)
                : extraStartMinutes + slotDuration;
            if (extraEndMinutes === null || extraEndMinutes > (24 * 60)) return;
            const startLabel = minutesToTime(extraStartMinutes);
            const endLabel = minutesToTime(extraEndMinutes);
            addSlot({
                date: dateString,
                startTime: startLabel,
                endTime: endLabel,
                startMinutes: extraStartMinutes,
                endMinutes: extraEndMinutes,
                timeRange: `${startLabel} - ${endLabel}`,
                value: `${dateString} ${startLabel}-${endLabel}`,
                label: `${formatDateDisplay(dateString)} ${startLabel}`,
                isExtra: true
            });
        });

        dateSlots.sort((a, b) => a.startMinutes - b.startMinutes);
        slots.push(...dateSlots);
        if (slots.length >= 1000) break;
    }

    return slots;
}

/**
 * Expands a recurrence into date strings starting from startDate.
 * recurrence: { freq: 'daily'|'weekly'|'monthly', until: 'YYYY-MM-DD' }
 */
export function expandRecurrence(recurrence, startDate) {
    if (!recurrence || !recurrence.freq || !startDate) return [];

    const { freq, until } = recurrence;
    const anchor = parseDateString(startDate);
    if (!anchor) return [];

    const untilDate = until ? parseDateString(until) : null;
    const results = [];
    const current = new Date(anchor);

    while (results.length < 730) {
        if (untilDate && current > untilDate) break;

        results.push(formatDate(current));

        if (freq === 'daily') {
            current.setDate(current.getDate() + 1);
        } else if (freq === 'weekly') {
            current.setDate(current.getDate() + 7);
        } else if (freq === 'monthly') {
            current.setMonth(current.getMonth() + 1);
        } else {
            break;
        }

        if (!untilDate && results.length >= 52) break;
    }

    return results;
}

export function groupSlotsByDate(slots) {
    return slots.reduce((acc, slot) => {
        if (!acc[slot.date]) acc[slot.date] = [];
        acc[slot.date].push(slot);
        return acc;
    }, {});
}

export function renderGroupedSlotsAccordion(slots, name, { onRemoveSlot, onAddSlot } = {}) {
    const groups = groupSlotsByDate(slots);

    return (
        <div className="rrze-appointment__accordion rrze-appointment__slots-grouped" data-accordion="open">
            <button type="button" className="rrze-appointment__accordion-toggle" aria-expanded="true">
                {__('All appointments', 'rrze-appointment')}
            </button>
            <div className="rrze-appointment__accordion-content">
                {Object.entries(groups).map(([date, dateSlots], index) => (
                    <div className="rrze-appointment__date-group" key={date} data-accordion={index === 0 ? 'open' : 'closed'}>
                        <button type="button" className="rrze-appointment__date-group-toggle" aria-expanded={index === 0 ? 'true' : 'false'}>
                            {formatDateDisplay(date)}
                        </button>
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
                                    aria-label={`Uhrzeit am ${formatDateDisplay(date)} hinzufügen`}
                                    onClick={() => onAddSlot(date)}
                                >
                                    +
                                </button>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
