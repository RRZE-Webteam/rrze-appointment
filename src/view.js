(function () {
    const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    function parseDate(value) {
        const [year, month, day] = value.split('-').map(Number);
        return new Date(year, (month || 1) - 1, day || 1);
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

    function buildDateMap(inputs) {
        const map = new Map();

        inputs.forEach((input) => {
            const value = input.value || '';
            const [date] = value.split(' ');
            if (!date) {
                return;
            }

            const label = input.closest('label')?.querySelector('span')?.textContent?.trim() || value;

            if (!map.has(date)) {
                map.set(date, []);
            }

            map.get(date).push({
                value,
                label
            });
        });

        return map;
    }

    function initAppointmentForm(form) {
        const calendar = form.querySelector('.rrze-appointment__calendar');
        const daySlotsFieldset = form.querySelector('.rrze-appointment__day-slots');
        const daySlotsList = form.querySelector('.rrze-appointment__day-slots-list');
        const groupedFieldset = form.querySelector('.rrze-appointment__slots-grouped');

        if (!calendar || !daySlotsFieldset || !daySlotsList || !groupedFieldset) {
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
        const preselected = form.querySelector('input[name="rrze_appointment_slot"]:checked');
        if (preselected) {
            const [selectedDate] = preselected.value.split(' ');
            if (dateSet.has(selectedDate)) {
                activeDate = selectedDate;
            }
        }

        function syncAllRadios(value) {
            form.querySelectorAll('input[name="rrze_appointment_slot"]').forEach((input) => {
                input.checked = input.value === value;
            });
        }

        function renderDaySlots(date) {
            const slots = dateMap.get(date) || [];

            daySlotsList.innerHTML = '';

            if (slots.length === 0) {
                daySlotsFieldset.classList.add('is-hidden');
                return;
            }

            daySlotsFieldset.classList.remove('is-hidden');

            const checked = form.querySelector('input[name="rrze_appointment_slot"]:checked')?.value || '';

            slots.forEach((slot) => {
                const label = document.createElement('label');
                label.className = 'rrze-appointment__slot-option';

                const input = document.createElement('input');
                input.type = 'radio';
                input.name = 'rrze_appointment_slot';
                input.value = slot.value;
                input.required = true;
                input.checked = slot.value === checked;

                const span = document.createElement('span');
                span.textContent = slot.label;

                label.appendChild(input);
                label.appendChild(span);
                daySlotsList.appendChild(label);
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

        form.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }

            if (target.name !== 'rrze_appointment_slot' || !target.checked) {
                return;
            }

            syncAllRadios(target.value);

            const [selectedDate] = target.value.split(' ');
            if (selectedDate && selectedDate !== activeDate) {
                activeDate = selectedDate;
                renderCalendar();
                renderDaySlots(activeDate);
            }
        });

        renderCalendar();
        renderDaySlots(activeDate);
    }

    document.querySelectorAll('form.rrze-appointment').forEach((form) => {
        initAppointmentForm(form);
    });
}());
