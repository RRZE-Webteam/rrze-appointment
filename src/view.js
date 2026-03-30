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
        let selectedSlotValue = '';
        let currentYear = firstDate.getFullYear();
        let currentMonth = firstDate.getMonth();

        const bookedSlots = new Set(window.rrze_appointment?.bookedSlots || []);

        function markHiddenInput(value) {
            groupedInputs.forEach((input) => {
                input.checked = input.value === value;
            });
        }

        function openOverlay(value, booker = {}) {
            const parsed = parseSlotValue(value);
            if (!parsed.date || !parsed.time) return;

            selectedSlotValue = value;
            markHiddenInput(value);

            const overlay = document.createElement('div');
            overlay.className = 'rrze-appointment__overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            const box = document.createElement('div');
            box.className = 'rrze-appointment__overlay-box';

            const text = document.createElement('p');
            text.className = 'rrze-appointment__overlay-text';
            text.textContent = `Ihr Termin am ${formatDateDisplay(parsed.date)} um ${parsed.time}`;

            const emailLabel = document.createElement('label');
            emailLabel.className = 'rrze-appointment__overlay-label';
            emailLabel.textContent = 'Ihre E-Mail-Adresse (für Erinnerungsmail):';
            const emailInput = document.createElement('input');
            emailInput.type = 'email';
            emailInput.className = 'rrze-appointment__overlay-email';
            emailInput.placeholder = 'name@example.de';
            emailInput.value = booker.bookerEmail || '';
            emailLabel.appendChild(emailInput);

            const nameLabel = document.createElement('label');
            nameLabel.className = 'rrze-appointment__overlay-label';
            nameLabel.textContent = 'Ihr Name (optional):';
            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.className = 'rrze-appointment__overlay-name';
            nameInput.placeholder = 'Vorname Nachname';
            nameInput.value = booker.bookerName || '';
            nameLabel.appendChild(nameInput);

            const messageLabel = document.createElement('label');
            messageLabel.className = 'rrze-appointment__overlay-label';
            messageLabel.textContent = 'Nachricht (optional):';
            const messageInput = document.createElement('textarea');
            messageInput.className = 'rrze-appointment__overlay-message';
            messageInput.rows = 4;
            messageLabel.appendChild(messageInput);

            const status = document.createElement('p');
            status.className = 'rrze-appointment__overlay-status';

            const actions = document.createElement('div');
            actions.className = 'rrze-appointment__overlay-actions';

            const confirmBtn = document.createElement('button');
            confirmBtn.type = 'button';
            confirmBtn.className = 'rrze-appointment__overlay-confirm';
            confirmBtn.textContent = 'Buchen';

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'rrze-appointment__overlay-cancel';
            cancelBtn.textContent = 'Abbrechen';

            function closeOverlay() {
                overlay.remove();
            }

            cancelBtn.addEventListener('click', closeOverlay);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) closeOverlay(); });
            document.addEventListener('keydown', function onKey(e) {
                if (e.key === 'Escape') { closeOverlay(); document.removeEventListener('keydown', onKey); }
            });

            confirmBtn.addEventListener('click', () => {
                confirmBtn.disabled = true;
                cancelBtn.disabled = true;
                status.textContent = 'Wird gebucht…';

                const data = new FormData();
                data.append('action', 'rrze_appointment_book');
                data.append('nonce', window.rrze_appointment?.nonce || '');
                data.append('slot', value);
                data.append('title', form.dataset.title || '');
                data.append('location', form.dataset.location || '');
                data.append('person_id', form.dataset.personId || '0');
                data.append('tpl_id', form.dataset.tplId || '0');
                data.append('booker_email', emailInput.value.trim());
                data.append('booker_name', nameInput.value.trim());
                data.append('booker_message', messageInput.value.trim());

                fetch(window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: data
                })
                    .then((r) => r.json())
                    .then((res) => {
                        if (res.success) {
                            bookedSlots.add(value);
                            status.textContent = 'Termin gebucht! Eine Bestätigung wurde versendet.';
                            confirmBtn.remove();
                            cancelBtn.textContent = 'Schließen';
                            cancelBtn.disabled = false;
                            renderCalendar();
                            renderDaySlots(activeDate);
                            renderGroupedSlots();
                        } else {
                            status.textContent = res.data || 'Fehler beim Buchen.';
                            confirmBtn.disabled = false;
                            cancelBtn.disabled = false;
                        }
                    })
                    .catch(() => {
                        status.textContent = 'Netzwerkfehler. Bitte erneut versuchen.';
                        confirmBtn.disabled = false;
                        cancelBtn.disabled = false;
                    });
            });

            actions.appendChild(confirmBtn);
            actions.appendChild(cancelBtn);
            box.appendChild(text);
            box.appendChild(emailLabel);
            box.appendChild(nameLabel);
            box.appendChild(messageLabel);
            box.appendChild(status);
            box.appendChild(actions);
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            confirmBtn.focus();
        }

        function createSlotButton(slot) {
            const isBooked = bookedSlots.has(slot.value);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rrze-appointment__slot-button';
            button.textContent = slot.label;
            button.dataset.slotValue = slot.value;

            if (isBooked) {
                button.classList.add('is-booked');
                button.disabled = true;
                return button;
            }

            if (selectedSlotValue && slot.value === selectedSlotValue) {
                button.classList.add('is-active');
            }

            button.addEventListener('click', () => {
                button.disabled = true;
                const data = new FormData();
                data.append('action', 'rrze_appointment_get_booker');
                fetch(window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: data
                })
                    .then((r) => r.json())
                    .then((res) => {
                        button.disabled = false;
                        if (res.data?.debug) console.log('RRZE Appointment get_booker:', res.data.debug);
                        if (res.success && res.data?.needsLogin) {
                            const loginUrl = res.data.loginUrl || '/wp-login.php';
                            const redirectTo = encodeURIComponent(window.location.href);
                            window.location.href = loginUrl.includes('redirect_to')
                                ? loginUrl
                                : loginUrl + (loginUrl.includes('?') ? '&' : '?') + 'redirect_to=' + redirectTo;
                            return;
                        }
                        const booker = res.success ? (res.data || {}) : {};
                        openOverlay(slot.value, booker);
                        renderDaySlots(activeDate);
                        renderGroupedSlots();
                    })
                    .catch(() => {
                        button.disabled = false;
                        openOverlay(slot.value, {});
                        renderDaySlots(activeDate);
                        renderGroupedSlots();
                    });
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
            const legend = daySlotsFieldset.querySelector('legend') || daySlotsFieldset.querySelector('.rrze-appointment__day-slots-title');
            if (legend) legend.textContent = `Verfügbare Termine am ${formatDateDisplay(date)}`;

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

            const monthDate = new Date(currentYear, currentMonth, 1);
            const year = monthDate.getFullYear();
            const monthIndex = monthDate.getMonth();

            const monthWrapper = document.createElement('div');
            monthWrapper.className = 'rrze-appointment__calendar-month';

            const titleRow = document.createElement('div');
            titleRow.className = 'rrze-appointment__calendar-title';

            const prevBtn = document.createElement('button');
            prevBtn.type = 'button';
            prevBtn.textContent = '‹';
            prevBtn.className = 'rrze-appointment__calendar-nav';
            prevBtn.addEventListener('click', () => {
                const prev = new Date(currentYear, currentMonth - 1, 1);
                currentYear = prev.getFullYear();
                currentMonth = prev.getMonth();
                renderCalendar();
            });

            const nextBtn = document.createElement('button');
            nextBtn.type = 'button';
            nextBtn.textContent = '›';
            nextBtn.className = 'rrze-appointment__calendar-nav';
            nextBtn.addEventListener('click', () => {
                const next = new Date(currentYear, currentMonth + 1, 1);
                currentYear = next.getFullYear();
                currentMonth = next.getMonth();
                renderCalendar();
            });

            const titleText = document.createElement('span');
            titleText.textContent = formatMonthTitle(monthDate);

            titleRow.appendChild(prevBtn);
            titleRow.appendChild(titleText);
            titleRow.appendChild(nextBtn);
            monthWrapper.appendChild(titleRow);

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

            const today = new Date();
            const todayStr = toDateString(today.getFullYear(), today.getMonth(), today.getDate());

            for (let day = 1; day <= daysInMonth; day += 1) {
                const dateString = toDateString(year, monthIndex, day);
                const dayOfWeek = new Date(year, monthIndex, day).getDay();
                const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
                const isPast = dateString < todayStr;
                const isToday = dateString === todayStr;
                const button = document.createElement('button');

                button.type = 'button';
                button.className = 'rrze-appointment__calendar-day';
                if (isWeekend || isPast) button.classList.add('is-past');
                if (isToday) button.classList.add('is-today');
                button.textContent = String(day);

                if (dateSet.has(dateString)) {
                    const dateSlots = dateMap.get(dateString) || [];
                    const allBooked = dateSlots.length > 0 && dateSlots.every((s) => bookedSlots.has(s.value));

                    if (!allBooked) button.classList.add('is-available');
                    if (allBooked) button.classList.add('is-booked');
                    if (dateString === activeDate) button.classList.add('is-active');

                    if (!allBooked) {
                        button.addEventListener('click', () => {
                            activeDate = dateString;
                            renderCalendar();
                            renderDaySlots(activeDate);
                        });
                    } else {
                        button.disabled = true;
                    }
                } else {
                    button.disabled = true;
                }

                grid.appendChild(button);
            }

            monthWrapper.appendChild(grid);
            calendar.appendChild(monthWrapper);
        }

        renderCalendar();
        renderDaySlots(activeDate);
        renderGroupedSlots();
    }

    document.querySelectorAll('form.rrze-appointment').forEach((form) => {
        initAppointmentForm(form);
    });
}());
