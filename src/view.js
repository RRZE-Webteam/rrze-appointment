(function () {
    const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    function parseDate(value) {
        const [year, month, day] = value.split('-').map(Number);
        return new Date(year, (month || 1) - 1, day || 1);
    }

    function formatDateDisplay(value) {
        const dateObj = parseDate(value);
        if (Number.isNaN(dateObj.getTime())) {
            return value;
        }
        return dateObj.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function formatMonthTitle(dateObj) {
        return dateObj.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
    }

    function toDateString(year, monthIndex, day) {
        return `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }

    function getMonthDiff(start, end) {
        return ((end.getFullYear() - start.getFullYear()) * 12) + (end.getMonth() - start.getMonth());
    }

    function parseSlotValue(value) {
        const [date, timeRange = ''] = value.split(' ');
        const [startTime = ''] = timeRange.split('-');

        return {
            date,
            time: startTime,
            value
        };
    }

    function buildDateMap(inputs) {
        const map = new Map();

        inputs.forEach((input) => {
            const value = input.value || '';
            const parsed = parseSlotValue(value);
            if (!parsed.date) {
                return;
            }

            const label = input.dataset.label?.trim() || input.closest('button')?.textContent?.trim()
                || input.closest('label')?.querySelector('span')?.textContent?.trim()
                || value;

            if (!map.has(parsed.date)) {
                map.set(parsed.date, []);
            }

            map.get(parsed.date).push({
                value,
                label,
                time: parsed.time
            });
        });

        return map;
    }

    function initAppointmentForm(form) {
        const calendar = form.querySelector('.rrze-appointment__calendar');
        const daySlotsFieldset = form.querySelector('.rrze-appointment__day-slots');
        const daySlotsList = form.querySelector('.rrze-appointment__day-slots-list');
        const groupedFieldset = form.querySelector('.rrze-appointment__slots-grouped');
        const selectedInfo = form.querySelector('.rrze-appointment__selected-info');
        const selectedText = form.querySelector('.rrze-appointment__selected-text');
        const bookButton = form.querySelector('.rrze-appointment__book-button');
        const bookStatus = form.querySelector('.rrze-appointment__book-status');

        if (!calendar || !daySlotsFieldset || !daySlotsList || !groupedFieldset || !selectedInfo || !selectedText || !bookButton || !bookStatus) {
            return;
        }

        const groupedInputs = Array.from(groupedFieldset.querySelectorAll('input[name="rrze_appointment_slot"]'));
        if (groupedInputs.length === 0) {
            return;
        }

        const dateMap = buildDateMap(groupedInputs);
        const availableDates = Array.from(dateMap.keys()).sort();
        if (availableDates.length === 0) {
            return;
        }

        const dateSet = new Set(availableDates);
        const firstDate = parseDate(availableDates[0]);
        const lastDate = parseDate(availableDates[availableDates.length - 1]);
        const totalMonths = getMonthDiff(firstDate, lastDate);

        let activeDate = availableDates[0];
        let selectedSlotValue = '';

        function markHiddenInput(value) {
            groupedInputs.forEach((input) => {
                input.checked = input.value === value;
            });
        }

        function showSelection(value) {
            const parsed = parseSlotValue(value);
            if (!parsed.date || !parsed.time) {
                return;
            }

            selectedSlotValue = value;
            markHiddenInput(value);

            selectedText.textContent = `Ihr Termin am ${formatDateDisplay(parsed.date)} um ${parsed.time}`;
            selectedInfo.classList.remove('is-hidden');
            bookStatus.textContent = '';
        }

        function createSlotButton(slot) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rrze-appointment__slot-button';
            button.textContent = slot.label;
            button.dataset.slotValue = slot.value;

            if (selectedSlotValue && slot.value === selectedSlotValue) {
                button.classList.add('is-active');
            }

            button.addEventListener('click', () => {
                showSelection(slot.value);
                renderDaySlots(activeDate);
                renderGroupedSlots();
            });

            return button;
        }

        function renderDaySlots(date) {
            const slots = dateMap.get(date) || [];

            daySlotsList.innerHTML = '';
            daySlotsList.className = 'rrze-appointment__day-slots-list rrze-appointment__slot-grid';

            if (slots.length === 0) {
                daySlotsFieldset.classList.add('is-hidden');
                return;
            }

            daySlotsFieldset.classList.remove('is-hidden');

            slots.forEach((slot) => {
                daySlotsList.appendChild(createSlotButton(slot));
            });
        }

        function renderGroupedSlots() {
            groupedFieldset.querySelectorAll('.rrze-appointment__slot-grid').forEach((grid) => {
                const date = grid.dataset.date;
                const slots = dateMap.get(date) || [];

                grid.innerHTML = '';
                slots.forEach((slot) => {
                    grid.appendChild(createSlotButton(slot));
                });
            });
        }

        function renderCalendar() {
            calendar.innerHTML = '';

            for (let i = 0; i <= totalMonths; i += 1) {
                const monthDate = new Date(firstDate.getFullYear(), firstDate.getMonth() + i, 1);
                const year = monthDate.getFullYear();
                const monthIndex = monthDate.getMonth();

                const monthWrapper = document.createElement('div');
                monthWrapper.className = 'rrze-appointment__calendar-month';

                const title = document.createElement('div');
                title.className = 'rrze-appointment__calendar-title';
                title.textContent = formatMonthTitle(monthDate);
                monthWrapper.appendChild(title);

                const grid = document.createElement('div');
                grid.className = 'rrze-appointment__calendar-grid';

                WEEKDAYS.forEach((weekday) => {
                    const cell = document.createElement('div');
                    cell.className = 'rrze-appointment__weekday';
                    cell.textContent = weekday;
                    grid.appendChild(cell);
                });

                const firstWeekdayIndex = (new Date(year, monthIndex, 1).getDay() + 6) % 7;
                const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();

                for (let e = 0; e < firstWeekdayIndex; e += 1) {
                    const empty = document.createElement('div');
                    empty.className = 'rrze-appointment__calendar-empty';
                    grid.appendChild(empty);
                }

                for (let day = 1; day <= daysInMonth; day += 1) {
                    const dateString = toDateString(year, monthIndex, day);
                    const button = document.createElement('button');

                    button.type = 'button';
                    button.className = 'rrze-appointment__calendar-day';
                    button.textContent = String(day);

                    if (dateSet.has(dateString)) {
                        button.classList.add('is-available');

                        if (dateString === activeDate) {
                            button.classList.add('is-active');
                        }

                        button.addEventListener('click', () => {
                            activeDate = dateString;
                            renderCalendar();
                            renderDaySlots(activeDate);
                        });
                    } else {
                        button.disabled = true;
                    }

                    grid.appendChild(button);
                }

                monthWrapper.appendChild(grid);
                calendar.appendChild(monthWrapper);
            }
        }

        bookButton.addEventListener('click', () => {
            if (!selectedSlotValue) {
                return;
            }

            bookStatus.textContent = 'gebucht';
        });

        renderCalendar();
        renderDaySlots(activeDate);
        renderGroupedSlots();
    }

    document.querySelectorAll('form.rrze-appointment').forEach((form) => {
        initAppointmentForm(form);
    });
}());
