/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./src/utils.ts"
/*!**********************!*\
  !*** ./src/utils.ts ***!
  \**********************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   MAX_RECURRENCE_DATES: () => (/* binding */ MAX_RECURRENCE_DATES),
/* harmony export */   expandRecurrence: () => (/* binding */ expandRecurrence),
/* harmony export */   formatDate: () => (/* binding */ formatDate),
/* harmony export */   formatDateDisplay: () => (/* binding */ formatDateDisplay),
/* harmony export */   formatDateLongDisplay: () => (/* binding */ formatDateLongDisplay),
/* harmony export */   formatDateWithWeekdayDisplay: () => (/* binding */ formatDateWithWeekdayDisplay),
/* harmony export */   generateTimeSlots: () => (/* binding */ generateTimeSlots),
/* harmony export */   getCalendarDates: () => (/* binding */ getCalendarDates),
/* harmony export */   getDateRange: () => (/* binding */ getDateRange),
/* harmony export */   getWeekdayMonthGridCells: () => (/* binding */ getWeekdayMonthGridCells),
/* harmony export */   groupSlotsByDate: () => (/* binding */ groupSlotsByDate),
/* harmony export */   minutesToTime: () => (/* binding */ minutesToTime),
/* harmony export */   normalizeDateList: () => (/* binding */ normalizeDateList),
/* harmony export */   parseDateString: () => (/* binding */ parseDateString),
/* harmony export */   parseTimeToMinutes: () => (/* binding */ parseTimeToMinutes),
/* harmony export */   recurrenceExceedsLimit: () => (/* binding */ recurrenceExceedsLimit)
/* harmony export */ });
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
  return hours * 60 + minutes;
}
function minutesToTime(totalMinutes) {
  const hours = Math.floor(totalMinutes / 60);
  const minutes = totalMinutes % 60;
  return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}
function formatDate(dateObj) {
  return `${dateObj.getFullYear()}-${String(dateObj.getMonth() + 1).padStart(2, '0')}-${String(dateObj.getDate()).padStart(2, '0')}`;
}

/**
 * Mo–Fr month grid without weekends: weekdays of the target month only, no placeholders for the previous month.
 * Leading empties align the first in-month weekday under Mo–Fr headers; trailing empties complete the last row.
 * @param year
 * @param monthIndex
 */
function getWeekdayMonthGridCells(year, monthIndex) {
  const daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
  const monthEnd = new Date(year, monthIndex, daysInMonth);
  const firstWd = new Date(year, monthIndex, 1);
  while (firstWd <= monthEnd) {
    const dow = firstWd.getDay();
    if (dow !== 0 && dow !== 6) {
      break;
    }
    firstWd.setDate(firstWd.getDate() + 1);
  }
  if (firstWd > monthEnd) {
    return [];
  }
  const firstDowMon0 = (firstWd.getDay() + 6) % 7;
  const cells = [];
  for (let i = 0; i < firstDowMon0; i += 1) {
    cells.push({
      type: 'empty'
    });
  }
  const cursor = new Date(firstWd);
  while (cursor <= monthEnd) {
    const dow = cursor.getDay();
    if (dow !== 0 && dow !== 6) {
      cells.push({
        type: 'day',
        day: cursor.getDate(),
        dateString: formatDate(cursor)
      });
    }
    cursor.setDate(cursor.getDate() + 1);
  }
  const trail = (5 - cells.length % 5) % 5;
  for (let i = 0; i < trail; i += 1) {
    cells.push({
      type: 'empty'
    });
  }
  return cells;
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
function formatDateDisplay(dateString, locale = 'de-DE') {
  const dateObj = parseDateString(dateString);
  if (!dateObj) {
    return dateString;
  }
  return dateObj.toLocaleDateString(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  });
}
function formatDateWithWeekdayDisplay(dateString, locale = 'de-DE') {
  const dateObj = parseDateString(dateString);
  if (!dateObj) {
    return dateString;
  }
  return dateObj.toLocaleDateString(locale, {
    weekday: 'long',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  });
}
function formatDateLongDisplay(dateString, locale = 'de-DE') {
  const dateObj = parseDateString(dateString);
  if (!dateObj) {
    return dateString;
  }
  return dateObj.toLocaleDateString(locale, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric'
  });
}
function normalizeDateList(values) {
  if (!Array.isArray(values)) {
    return [];
  }
  return [...new Set(values.map(value => {
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
  const selectedEndDate = attributes.useEndDate && attributes.endDate ? attributes.endDate : attributes.startDate;
  return getDateRange(attributes.startDate, selectedEndDate);
}
function getRecurrenceAnchor(attributes, date) {
  const rules = attributes.recurrences && typeof attributes.recurrences === 'object' ? attributes.recurrences : {};
  const matchingRule = Object.entries(rules).find(([, rule]) => Array.isArray(rule.dates) && rule.dates.includes(date));
  if (matchingRule) {
    return matchingRule[0];
  }
  const legacyRule = attributes.recurrence;
  if (Array.isArray(legacyRule?.dates) && legacyRule.dates.includes(date)) {
    return legacyRule.anchor || legacyRule.dates[0] || '';
  }
  return '';
}
function getAvailabilityWindows(attributes) {
  const overrides = attributes.dateOverrides && typeof attributes.dateOverrides === 'object' ? attributes.dateOverrides : {};
  if (Array.isArray(attributes.availabilities)) {
    return attributes.availabilities.flatMap(entry => {
      if (!parseDateString(entry.date)) {
        return [];
      }
      const recurrenceDates = entry.recurrence?.freq ? normalizeDateList(Array.isArray(entry.recurrence.dates) ? entry.recurrence.dates : expandRecurrence(entry.recurrence, entry.date)) : [entry.date];
      const excludedDates = new Set(normalizeDateList(entry.recurrence?.excludedDates));
      return recurrenceDates.filter(date => !excludedDates.has(date)).map(date => {
        const override = overrides[date] || {};
        return {
          date,
          startTime: entry.startTime,
          endTime: entry.endTime,
          duration: Number(entry.duration),
          breakDuration: Number(entry.breakDuration),
          removedSlots: Array.isArray(override.removedSlots) ? override.removedSlots : [],
          extraSlots: Array.isArray(override.extraSlots) ? override.extraSlots : []
        };
      });
    });
  }
  const globalDuration = Number(attributes.duration);
  const globalPause = Number(attributes.breakDuration);
  return getCalendarDates(attributes).map(date => {
    const recurrenceAnchor = getRecurrenceAnchor(attributes, date);
    const seriesOverride = recurrenceAnchor ? overrides[recurrenceAnchor] || {} : {};
    const override = {
      ...seriesOverride,
      ...(overrides[date] || {})
    };
    return {
      date,
      startTime: override.startTime || attributes.startTime,
      endTime: override.endTime || attributes.endTime,
      duration: override.duration !== undefined ? Number(override.duration) : globalDuration,
      breakDuration: override.breakDuration !== undefined ? Number(override.breakDuration) : globalPause,
      removedSlots: Array.isArray(override.removedSlots) ? override.removedSlots : [],
      extraSlots: Array.isArray(override.extraSlots) ? override.extraSlots : []
    };
  });
}
function generateTimeSlots(attributes, options = {}) {
  const windows = getAvailabilityWindows(attributes);
  const now = new Date();
  const slots = [];
  const slotMap = new Map();
  const processedExtraDates = new Set();
  const includeExcluded = !!options.includeExcluded;
  const addSlot = (slot, removedSlots) => {
    const slotStart = new Date(`${slot.date}T${slot.startTime}:00`);
    if (!Number.isNaN(slotStart.getTime()) && slotStart <= now) {
      return;
    }
    const isExcluded = removedSlots.has(slot.value);
    if (isExcluded && !includeExcluded || slotMap.has(slot.value)) {
      return;
    }
    const nextSlot = {
      ...slot,
      isExcluded
    };
    slotMap.set(slot.value, nextSlot);
    slots.push(nextSlot);
  };
  for (const window of windows) {
    const {
      date,
      duration,
      breakDuration,
      startTime,
      endTime,
      extraSlots
    } = window;
    if (!Number.isFinite(duration) || !Number.isInteger(duration) || duration <= 0 || !Number.isFinite(breakDuration) || breakDuration < 0 || breakDuration > 55 || breakDuration % 5 !== 0) {
      continue;
    }
    const startMinutes = parseTimeToMinutes(startTime);
    const endMinutes = parseTimeToMinutes(endTime);
    if (startMinutes === null || endMinutes === null || endMinutes <= startMinutes) {
      continue;
    }
    const removedSlots = new Set(window.removedSlots);
    let slotStart = startMinutes;
    while (slotStart + duration <= endMinutes) {
      const slotEnd = slotStart + duration;
      const startLabel = minutesToTime(slotStart);
      const endLabel = minutesToTime(slotEnd);
      addSlot({
        date,
        startTime: startLabel,
        endTime: endLabel,
        startMinutes: slotStart,
        endMinutes: slotEnd,
        timeRange: `${startLabel} - ${endLabel}`,
        value: `${date} ${startLabel}-${endLabel}`,
        label: `${formatDateDisplay(date)} ${startLabel}`,
        isExtra: false
      }, removedSlots);
      slotStart += duration + breakDuration;
    }
    if (processedExtraDates.has(date)) {
      continue;
    }
    processedExtraDates.add(date);
    extraSlots.forEach(extraEntry => {
      const hasPipe = typeof extraEntry === 'string' && extraEntry.includes('|');
      const extraStart = hasPipe ? extraEntry.split('|')[0] : extraEntry;
      const extraEnd = hasPipe ? extraEntry.split('|')[1] : null;
      const extraStartMinutes = parseTimeToMinutes(extraStart);
      if (extraStartMinutes === null) {
        return;
      }
      const extraEndMinutes = extraEnd ? parseTimeToMinutes(extraEnd) : extraStartMinutes + duration;
      if (extraEndMinutes === null || extraEndMinutes > 24 * 60) {
        return;
      }
      const startLabel = minutesToTime(extraStartMinutes);
      const endLabel = minutesToTime(extraEndMinutes);
      addSlot({
        date,
        startTime: startLabel,
        endTime: endLabel,
        startMinutes: extraStartMinutes,
        endMinutes: extraEndMinutes,
        timeRange: `${startLabel} - ${endLabel}`,
        value: `${date} ${startLabel}-${endLabel}`,
        label: `${formatDateDisplay(date)} ${startLabel}`,
        isExtra: true
      }, removedSlots);
    });
    if (slots.length >= 1000) {
      break;
    }
  }
  return slots.sort((a, b) => a.date.localeCompare(b.date) || a.startMinutes - b.startMinutes || a.endMinutes - b.endMinutes);
}

/**
 * Expands a recurrence into date strings starting from startDate.
 * recurrence: { freq: 'daily'|'weekly'|'monthly', until: 'YYYY-MM-DD', count: number }
 * @param recurrence
 * @param startDate
 */
const MAX_RECURRENCE_DATES = 730;
const DEFAULT_LEGACY_RECURRENCE_DATES = 52;
function getLegacyRecurrenceLimit() {
  const configuredLimit = Number(typeof window !== 'undefined' ? window.rrze_appointment?.recurrenceLimit : DEFAULT_LEGACY_RECURRENCE_DATES);
  return Number.isInteger(configuredLimit) && configuredLimit > 0 ? Math.min(configuredLimit, MAX_RECURRENCE_DATES) : DEFAULT_LEGACY_RECURRENCE_DATES;
}
function expandRecurrenceWithLimit(recurrence, startDate, maxDates) {
  if (!recurrence || !recurrence.freq || !startDate) {
    return [];
  }
  const {
    freq,
    until
  } = recurrence;
  const anchor = parseDateString(startDate);
  if (!anchor) {
    return [];
  }
  const untilDate = until ? parseDateString(until) : null;
  const results = [];
  const requestedCount = Number(recurrence.count);
  let occurrenceLimit = maxDates;
  if (!untilDate) {
    occurrenceLimit = Number.isInteger(requestedCount) && requestedCount > 0 ? Math.min(requestedCount, maxDates) : Math.min(getLegacyRecurrenceLimit(), maxDates);
  }
  const weekdays = freq === 'weekly' && Array.isArray(recurrence.weekdays) ? new Set(recurrence.weekdays.filter(weekday => Number.isInteger(weekday) && weekday >= 0 && weekday <= 6)) : null;
  if (weekdays) {
    if (weekdays.size === 0) {
      return [];
    }
    const current = new Date(anchor);
    while (results.length < occurrenceLimit) {
      if (untilDate && current > untilDate) {
        break;
      }
      if (weekdays.has(current.getDay())) {
        results.push(formatDate(current));
      }
      current.setDate(current.getDate() + 1);
    }
    return results;
  }
  let current = new Date(anchor);
  let occurrenceIndex = 0;
  while (results.length < occurrenceLimit) {
    if (untilDate && current > untilDate) {
      break;
    }
    results.push(formatDate(current));
    occurrenceIndex += 1;
    if (freq === 'daily') {
      current.setDate(current.getDate() + 1);
    } else if (freq === 'weekly') {
      current.setDate(current.getDate() + 7);
    } else if (freq === 'monthly') {
      const targetMonth = new Date(anchor.getFullYear(), anchor.getMonth() + occurrenceIndex, 1);
      const lastDayOfTargetMonth = new Date(targetMonth.getFullYear(), targetMonth.getMonth() + 1, 0).getDate();
      current = new Date(targetMonth.getFullYear(), targetMonth.getMonth(), Math.min(anchor.getDate(), lastDayOfTargetMonth));
    } else {
      break;
    }
  }
  return results;
}
function expandRecurrence(recurrence, startDate) {
  return expandRecurrenceWithLimit(recurrence, startDate, MAX_RECURRENCE_DATES);
}
function recurrenceExceedsLimit(recurrence, startDate) {
  const requestedCount = Number(recurrence.count);
  if (!recurrence.until && Number.isInteger(requestedCount) && requestedCount > MAX_RECURRENCE_DATES) {
    return true;
  }
  if (!recurrence.until) {
    return false;
  }
  return expandRecurrenceWithLimit(recurrence, startDate, MAX_RECURRENCE_DATES + 1).length > MAX_RECURRENCE_DATES;
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

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		if (!(moduleId in __webpack_modules__)) {
/******/ 			delete __webpack_module_cache__[moduleId];
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!*********************!*\
  !*** ./src/view.ts ***!
  \*********************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _utils__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./utils */ "./src/utils.ts");

(function () {
  function run() {
    const i18n = window.rrze_appointment?.i18n || {};
    const requestedLocale = window.rrze_appointment?.locale || document.documentElement.lang || 'de-DE';
    let frontendLocale = requestedLocale;
    try {
      new Intl.DateTimeFormat(frontendLocale).format();
    } catch (error) {
      frontendLocale = 'de-DE';
    }
    const weekdays = Array.from({
      length: 7
    }, (unused, index) => {
      const date = new Date(2024, 0, index + 1);
      return {
        short: date.toLocaleDateString(frontendLocale, {
          weekday: 'short'
        }),
        long: date.toLocaleDateString(frontendLocale, {
          weekday: 'long'
        })
      };
    });
    function formatMonthTitle(dateObj) {
      return dateObj.toLocaleDateString(frontendLocale, {
        month: 'long',
        year: 'numeric'
      });
    }
    function toDateString(year, monthIndex, day) {
      return `${year}-${String(monthIndex + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    }
    function parseSlotValue(value) {
      const slotString = String(value || '').trim();
      const match = slotString.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})(?:-(\d{1,2}:\d{2}))?/);
      const date = match ? match[1] : '';
      const startTime = match ? match[2] : '';
      const endTime = match ? match[3] || '' : '';
      return {
        date,
        time: startTime,
        endTime,
        value: slotString
      };
    }
    function formatSlotLabelFromValue(value) {
      const parsed = parseSlotValue(value);
      if (!parsed.time) {
        return '';
      }
      if (parsed.endTime) {
        return `${parsed.time} - ${parsed.endTime}`;
      }
      return parsed.time;
    }
    function buildDateMap(inputs) {
      const map = new Map();
      inputs.forEach(input => {
        const value = input.value || '';
        const parsed = parseSlotValue(value);
        if (!parsed.date) {
          return;
        }
        const fallbackTimeLabel = formatSlotLabelFromValue(value);
        const label = input.dataset.label?.trim() || input.closest('button')?.textContent?.trim() || input.closest('label')?.querySelector('span')?.textContent?.trim() || fallbackTimeLabel || value;
        const dateSlots = map.get(parsed.date) || [];
        dateSlots.push({
          value,
          label,
          time: parsed.time
        });
        map.set(parsed.date, dateSlots);
      });
      return map;
    }
    function initAppointmentForm(form, instanceId) {
      const calendar = form.querySelector('.rrze-appointment__calendar');
      const daySlotsContainer = form.querySelector('.rrze-appointment__day-slots');
      const daySlotsList = form.querySelector('.rrze-appointment__day-slots-list');
      const slotData = form.querySelector('.rrze-appointment__slot-data');
      if (!calendar || !daySlotsContainer || !daySlotsList || !slotData) {
        return;
      }
      const calendarElement = calendar;
      const daySlotsContainerElement = daySlotsContainer;
      const daySlotsListElement = daySlotsList;
      const slotDataElement = slotData;
      const availabilityStatusElement = form.querySelector('.rrze-appointment__availability-status');
      const selectedInfoElement = form.querySelector('.rrze-appointment__selected-info');
      daySlotsListElement.id = `${instanceId}-slots`;
      calendarElement.setAttribute('role', 'group');
      calendarElement.setAttribute('aria-label', i18n.chooseDate || 'Choose an appointment date');
      function showAvailabilityMessage(message) {
        if (!availabilityStatusElement) {
          return;
        }
        availabilityStatusElement.textContent = message;
        availabilityStatusElement.classList.remove('is-hidden');
      }
      function clearAvailabilityMessage() {
        if (!availabilityStatusElement) {
          return;
        }
        availabilityStatusElement.textContent = '';
        availabilityStatusElement.classList.add('is-hidden');
      }
      function setInteractionStatus(message = '') {
        if (!selectedInfoElement) {
          return;
        }
        selectedInfoElement.textContent = message;
        selectedInfoElement.classList.toggle('is-hidden', !message);
      }
      const slotInputs = Array.from(slotDataElement.querySelectorAll('input[name="rrze_appointment_slot"]'));
      if (slotInputs.length === 0) {
        return;
      }
      const dateMap = buildDateMap(slotInputs);
      let availableDates = [];
      let dateSet = new Set();
      let activeDate = '';
      let selectedSlotValue = '';
      const bookedSlots = new Set(window.rrze_appointment?.bookedSlots || []);
      const bookingCutoff = parseInt(form.dataset.bookingCutoff || '0', 10);
      const disableSso = form.dataset.disableSso === '1';
      const hideWeekends = form.dataset.hideWeekends === '1';
      function parseSlotStart(slotValue) {
        const parsed = parseSlotValue(slotValue);
        if (!parsed.date || !parsed.time) {
          return null;
        }
        const slotStart = new Date(`${parsed.date}T${parsed.time}:00`);
        return Number.isNaN(slotStart.getTime()) ? null : slotStart;
      }
      function isSlotInPast(slotValue) {
        const slotStart = parseSlotStart(slotValue);
        if (!slotStart) {
          return false;
        }
        return slotStart <= new Date();
      }
      function isSlotCutoff(slotValue) {
        if (!bookingCutoff) {
          return false;
        }
        const slotStart = parseSlotStart(slotValue);
        if (!slotStart) {
          return false;
        }
        return slotStart.getTime() - Date.now() < bookingCutoff * 60 * 1000;
      }
      function isSlotUnavailable(slotValue) {
        return bookedSlots.has(slotValue) || isSlotInPast(slotValue) || isSlotCutoff(slotValue);
      }
      function refreshAvailabilityMessage() {
        const hasAvailableSlot = Array.from(dateMap.values()).some(slots => slots.some(slot => !isSlotUnavailable(slot.value)));
        if (hasAvailableSlot) {
          clearAvailabilityMessage();
          return;
        }
        showAvailabilityMessage(i18n.noSlotsAvailable || 'No time slots available.');
      }
      dateMap.forEach((slots, date) => {
        const filtered = slots.filter(slot => !isSlotUnavailable(slot.value));
        if (filtered.length > 0) {
          dateMap.set(date, filtered);
        } else {
          dateMap.delete(date);
        }
      });
      availableDates = Array.from(dateMap.keys()).sort();
      if (availableDates.length === 0) {
        calendarElement.innerHTML = '';
        daySlotsContainerElement.classList.add('is-hidden');
        showAvailabilityMessage(i18n.noSlotsAvailable || 'No time slots available.');
        return;
      }
      clearAvailabilityMessage();
      dateSet = new Set(availableDates);
      const firstDate = new Date(`${availableDates[0]}T00:00:00`);
      activeDate = availableDates[0];
      let currentYear = firstDate.getFullYear();
      let currentMonth = firstDate.getMonth();
      function markHiddenInput(value) {
        slotInputs.forEach(input => {
          input.checked = input.value === value;
        });
      }
      function openOverlay(value, booker = {}, triggerButton = null) {
        const existingDialog = document.querySelector('.rrze-appointment__overlay-box[role="dialog"]');
        if (existingDialog) {
          existingDialog.focus();
          return;
        }
        if (isSlotUnavailable(value)) {
          return;
        }
        const parsed = parseSlotValue(value);
        if (!parsed.date || !parsed.time) {
          return;
        }
        selectedSlotValue = value;
        markHiddenInput(value);
        form.querySelectorAll('.rrze-appointment__slot-button.is-active').forEach(activeButton => {
          activeButton.classList.remove('is-active');
        });
        triggerButton?.classList.add('is-active');
        const titleId = `${instanceId}-dialog-title`;
        const introId = `${instanceId}-dialog-intro`;
        const appointmentId = `${instanceId}-dialog-appointment`;
        const statusId = `${instanceId}-dialog-status`;
        const overlay = document.createElement('div');
        overlay.className = 'rrze-appointment__overlay';
        const box = document.createElement('div');
        box.className = 'rrze-appointment__overlay-box';
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.tabIndex = -1;
        box.setAttribute('aria-labelledby', titleId);
        box.setAttribute('aria-describedby', `${introId} ${appointmentId}`);
        const header = document.createElement('div');
        header.className = 'rrze-appointment__overlay-header';
        const heading = document.createElement('h2');
        heading.className = 'rrze-appointment__overlay-title';
        heading.id = titleId;
        heading.textContent = i18n.dialogTitle || 'Request appointment';
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'rrze-appointment__overlay-close';
        closeBtn.setAttribute('aria-label', i18n.closeDialog || 'Close dialog');
        closeBtn.textContent = '×';
        header.appendChild(heading);
        header.appendChild(closeBtn);
        const intro = document.createElement('p');
        intro.className = 'rrze-appointment__overlay-intro';
        intro.id = introId;
        intro.textContent = i18n.dialogIntro || 'Enter your details to request this appointment. You will receive an email to confirm it.';
        const appointment = document.createElement('div');
        appointment.className = 'rrze-appointment__overlay-appointment';
        appointment.id = appointmentId;
        const appointmentLabel = document.createElement('span');
        appointmentLabel.className = 'rrze-appointment__overlay-appointment-label';
        appointmentLabel.textContent = i18n.selectedAppointment || 'Selected appointment';
        const appointmentDate = document.createElement('strong');
        appointmentDate.className = 'rrze-appointment__overlay-appointment-date';
        appointmentDate.textContent = (0,_utils__WEBPACK_IMPORTED_MODULE_0__.formatDateDisplay)(parsed.date, frontendLocale);
        const appointmentTime = document.createElement('span');
        appointmentTime.className = 'rrze-appointment__overlay-appointment-time';
        appointmentTime.textContent = parsed.endTime ? `${parsed.time}–${parsed.endTime}` : parsed.time;
        appointment.appendChild(appointmentLabel);
        appointment.appendChild(appointmentDate);
        appointment.appendChild(appointmentTime);
        const previousBodyOverflow = document.body.style.overflow;
        const inertedSiblings = [];
        function isolateDialog() {
          Array.from(document.body.children).forEach(child => {
            if (child !== overlay && child instanceof HTMLElement && !child.hasAttribute('inert')) {
              child.setAttribute('inert', '');
              inertedSiblings.push(child);
            }
          });
        }
        function restorePage() {
          inertedSiblings.forEach(sibling => {
            sibling.removeAttribute('inert');
          });
        }

        // Focus-Trap: alle fokussierbaren Elemente im Dialog
        function getFocusable() {
          return Array.from(box.querySelectorAll('input, textarea, select, button:not([disabled]), [tabindex]:not([tabindex="-1"])'));
        }
        function trapFocus(e) {
          if (e.key !== 'Tab') {
            return;
          }
          const focusable = getFocusable();
          if (!focusable.length) {
            return;
          }
          const first = focusable[0];
          const last = focusable[focusable.length - 1];
          if (e.shiftKey) {
            if (box.ownerDocument.activeElement === first) {
              e.preventDefault();
              last.focus();
            }
          } else if (box.ownerDocument.activeElement === last) {
            e.preventDefault();
            first.focus();
          }
        }
        const dialogForm = document.createElement('form');
        dialogForm.className = 'rrze-appointment__overlay-form';
        dialogForm.noValidate = true;
        const fields = document.createElement('div');
        fields.className = 'rrze-appointment__overlay-fields';
        const nameLabel = document.createElement('label');
        nameLabel.className = 'rrze-appointment__overlay-label';
        const nameLabelText = document.createElement('span');
        nameLabelText.textContent = `${i18n.yourName || 'Name'} (${i18n.required || 'required'})`;
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.id = `${instanceId}-name`;
        nameInput.className = 'rrze-appointment__overlay-name';
        nameInput.autocomplete = 'name';
        nameInput.placeholder = i18n.namePlaceholder || 'First and last name';
        nameInput.value = booker.bookerName || '';
        nameInput.readOnly = !!booker.bookerName;
        nameInput.required = true;
        nameLabel.appendChild(nameLabelText);
        nameLabel.appendChild(nameInput);
        const emailLabel = document.createElement('label');
        emailLabel.className = 'rrze-appointment__overlay-label';
        const emailLabelText = document.createElement('span');
        emailLabelText.textContent = `${i18n.yourEmail || 'Email address'} (${i18n.required || 'required'})`;
        const emailInput = document.createElement('input');
        emailInput.type = 'email';
        emailInput.id = `${instanceId}-email`;
        emailInput.className = 'rrze-appointment__overlay-email';
        emailInput.autocomplete = 'email';
        emailInput.placeholder = 'name@example.com';
        emailInput.value = booker.bookerEmail || '';
        emailInput.readOnly = !!booker.bookerEmail;
        emailInput.required = true;
        emailLabel.appendChild(emailLabelText);
        emailLabel.appendChild(emailInput);
        const waitlistLabel = document.createElement('label');
        waitlistLabel.className = 'rrze-appointment__overlay-waitlist rrze-appointment__overlay-label--wide';
        const waitlistCheckbox = document.createElement('input');
        waitlistCheckbox.type = 'checkbox';
        waitlistCheckbox.className = 'rrze-appointment__overlay-waitlist-checkbox';
        waitlistLabel.appendChild(waitlistCheckbox);
        waitlistLabel.appendChild(document.createTextNode(' ' + (window.rrze_appointment?.i18n?.waitlist || 'Notify me if an earlier appointment becomes available.')));
        const status = document.createElement('p');
        status.id = statusId;
        status.className = 'rrze-appointment__overlay-status is-hidden';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
        const actions = document.createElement('div');
        actions.className = 'rrze-appointment__overlay-actions';
        const confirmBtn = document.createElement('button');
        confirmBtn.type = 'submit';
        confirmBtn.className = 'rrze-appointment__overlay-confirm';
        confirmBtn.textContent = i18n.book || 'Request appointment';
        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'rrze-appointment__overlay-cancel';
        cancelBtn.textContent = i18n.cancel || 'Cancel';
        let isClosed = false;
        let isSubmitting = false;
        function closeOverlay() {
          if (isClosed || isSubmitting) {
            return;
          }
          isClosed = true;
          document.removeEventListener('keydown', onKey);
          document.body.style.overflow = previousBodyOverflow;
          restorePage();
          overlay.remove();
          if (triggerButton?.isConnected && !triggerButton.disabled) {
            triggerButton.focus();
            return;
          }
          const slotReplacement = Array.from(form.querySelectorAll('.rrze-appointment__slot-button')).find(button => button.dataset.slotValue === value && !button.disabled);
          const fallback = slotReplacement || form.querySelector('.rrze-appointment__day-slots:not(.is-hidden) .rrze-appointment__day-slots-title') || form.querySelector('.rrze-appointment__calendar-month-title');
          fallback?.focus();
        }
        function onKey(e) {
          if (e.key === 'Escape') {
            closeOverlay();
          }
        }
        function clearStatus() {
          status.textContent = '';
          status.className = 'rrze-appointment__overlay-status is-hidden';
          status.setAttribute('role', 'status');
          status.setAttribute('aria-live', 'polite');
        }
        function showStatus(message, type) {
          status.textContent = message;
          status.className = `rrze-appointment__overlay-status is-${type}`;
          status.setAttribute('role', type === 'error' ? 'alert' : 'status');
          status.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        }
        function showFieldError(input, message) {
          input.setAttribute('aria-invalid', 'true');
          input.setAttribute('aria-describedby', statusId);
          input.setAttribute('aria-errormessage', statusId);
          showStatus(message, 'error');
          input.focus();
        }
        [nameInput, emailInput].forEach(input => {
          input.addEventListener('input', () => {
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
            input.removeAttribute('aria-errormessage');
            if (status.classList.contains('is-error')) {
              clearStatus();
            }
          });
          input.addEventListener('change', () => {
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
            input.removeAttribute('aria-errormessage');
            if (status.classList.contains('is-error')) {
              clearStatus();
            }
          });
        });
        closeBtn.addEventListener('click', closeOverlay);
        cancelBtn.addEventListener('click', closeOverlay);
        overlay.addEventListener('click', e => {
          if (e.target === overlay) {
            closeOverlay();
          }
        });
        overlay.addEventListener('keydown', trapFocus);
        document.addEventListener('keydown', onKey);
        dialogForm.addEventListener('submit', event => {
          event.preventDefault();
          if (isSubmitting) {
            return;
          }
          clearStatus();
          const nameValue = nameInput.value.trim();
          if (!nameValue) {
            showFieldError(nameInput, i18n.nameRequired || 'Enter your name.');
            return;
          }
          const emailValue = emailInput.value.trim();
          const emailIsValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue);
          if (!emailValue || !emailIsValid) {
            showFieldError(emailInput, i18n.emailRequired || 'Enter a valid email address.');
            return;
          }
          confirmBtn.disabled = true;
          cancelBtn.disabled = true;
          closeBtn.disabled = true;
          isSubmitting = true;
          dialogForm.setAttribute('aria-busy', 'true');
          showStatus(i18n.booking || 'Sending request…', 'loading');
          const data = new FormData();
          data.append('action', 'rrze_appointment_book');
          data.append('nonce', window.rrze_appointment?.nonce || '');
          data.append('slot', value);
          data.append('post_id', form.dataset.postId || '0');
          data.append('block_id', form.dataset.blockId || '');
          data.append('booker_email', emailValue);
          data.append('booker_name', nameValue);
          data.append('booker_waitlist', waitlistCheckbox.checked ? '1' : '0');
          fetch(window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: data
          }).then(r => r.json()).then(res => {
            if (res.success) {
              bookedSlots.add(value);
              isSubmitting = false;
              dialogForm.removeAttribute('aria-busy');
              showStatus(i18n.booked || 'Check your inbox to confirm the appointment. We sent a confirmation link to your email address.', 'success');
              fields.hidden = true;
              intro.hidden = true;
              heading.textContent = i18n.successTitle || 'Check your inbox';
              heading.tabIndex = -1;
              box.classList.add('is-success');
              confirmBtn.remove();
              cancelBtn.textContent = i18n.close || 'Close';
              cancelBtn.disabled = false;
              closeBtn.disabled = false;
              renderCalendar();
              renderDaySlots(activeDate);
              refreshAvailabilityMessage();
              heading.focus();
            } else {
              isSubmitting = false;
              dialogForm.removeAttribute('aria-busy');
              const responseMessage = typeof res.data === 'string' ? res.data : res.data?.message;
              showStatus(responseMessage || i18n.bookingError || "We couldn't request this appointment. Please try again.", 'error');
              confirmBtn.disabled = false;
              cancelBtn.disabled = false;
              closeBtn.disabled = false;
            }
          }).catch(() => {
            isSubmitting = false;
            dialogForm.removeAttribute('aria-busy');
            showStatus(i18n.networkError || 'Connection problem. Check your internet connection and try again.', 'error');
            confirmBtn.disabled = false;
            cancelBtn.disabled = false;
            closeBtn.disabled = false;
          });
        });
        actions.appendChild(cancelBtn);
        actions.appendChild(confirmBtn);
        fields.appendChild(nameLabel);
        fields.appendChild(emailLabel);
        fields.appendChild(waitlistLabel);
        dialogForm.appendChild(fields);
        dialogForm.appendChild(status);
        dialogForm.appendChild(actions);
        box.appendChild(header);
        box.appendChild(intro);
        box.appendChild(appointment);
        box.appendChild(dialogForm);
        overlay.appendChild(box);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        if (!nameInput.value) {
          nameInput.focus();
        } else if (!emailInput.value) {
          emailInput.focus();
        } else {
          confirmBtn.focus();
        }
        isolateDialog();
      }
      function createSlotButton(slot) {
        const isBooked = isSlotUnavailable(slot.value);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'rrze-appointment__slot-button';
        button.textContent = slot.label;
        button.dataset.slotValue = slot.value;
        const slotDate = parseSlotValue(slot.value).date;
        if (slotDate) {
          button.setAttribute('aria-label', `${slot.label}, ${(0,_utils__WEBPACK_IMPORTED_MODULE_0__.formatDateLongDisplay)(slotDate, frontendLocale)}`);
        }
        if (isBooked) {
          button.classList.add('is-booked');
          button.disabled = true;
          return button;
        }
        if (selectedSlotValue && slot.value === selectedSlotValue) {
          button.classList.add('is-active');
        }
        button.addEventListener('click', () => {
          if (disableSso) {
            openOverlay(slot.value, {}, button);
            return;
          }
          button.disabled = true;
          button.setAttribute('aria-busy', 'true');
          form.setAttribute('aria-busy', 'true');
          setInteractionStatus(i18n.bookingDetailsLoading || 'Loading booking details…');
          fetch(window.rrze_appointment?.restUrl || '/wp-json/rrze/v2/appointment/booker', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              returnTo: window.location.href.split('#')[0]
            })
          }).then(async r => {
            const text = await r.text();
            const trimmed = text.trim();
            if (trimmed.startsWith('<!DOCTYPE') || trimmed.startsWith('<html')) {
              document.open();
              document.write(text);
              document.close();
              return null;
            }
            try {
              return JSON.parse(text);
            } catch (e) {
              throw new Error('Invalid JSON response: ' + text);
            }
          }).then(res => {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            setInteractionStatus();
            if (!res) {
              return;
            }
            if (res.needsLogin) {
              const loginUrl = (res.loginUrl || '').trim();
              if (!loginUrl) {
                return;
              }
              sessionStorage.setItem('rrze_appt_slot', slot.value);
              sessionStorage.setItem('rrze_appt_page', window.location.href.split('#')[0]);
              window.location.href = loginUrl;
              return;
            }
            const booker = res.data || {};
            openOverlay(slot.value, booker, button);
          }).catch(() => {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            setInteractionStatus();
            openOverlay(slot.value, {}, button);
          });
        });
        return button;
      }
      function renderDaySlots(date, focusTitle = false) {
        const slots = (dateMap.get(date) || []).filter(slot => !isSlotUnavailable(slot.value));
        daySlotsListElement.innerHTML = '';
        daySlotsListElement.className = 'rrze-appointment__day-slots-list rrze-appointment__slot-grid';
        if (slots.length === 0) {
          daySlotsContainerElement.classList.add('is-hidden');
          return;
        }
        daySlotsContainerElement.classList.remove('is-hidden');
        const title = daySlotsContainerElement.querySelector('.rrze-appointment__day-slots-title');
        if (title) {
          title.textContent = (i18n.availableOn || 'Available appointments on %s').replace('%s', (0,_utils__WEBPACK_IMPORTED_MODULE_0__.formatDateDisplay)(date, frontendLocale));
          title.tabIndex = -1;
        }
        slots.forEach(slot => {
          daySlotsListElement.appendChild(createSlotButton(slot));
        });
        if (focusTitle && title instanceof HTMLElement) {
          title.focus();
        }
      }
      function renderCalendar(focusNavigation = null) {
        calendarElement.innerHTML = '';
        const monthDate = new Date(currentYear, currentMonth, 1);
        const year = monthDate.getFullYear();
        const monthIndex = monthDate.getMonth();
        const monthWrapper = document.createElement('div');
        monthWrapper.className = 'rrze-appointment__calendar-month';
        const titleRow = document.createElement('div');
        titleRow.className = 'rrze-appointment__calendar-title';
        const previousMonth = new Date(currentYear, currentMonth - 1, 1);
        const nextMonth = new Date(currentYear, currentMonth + 1, 1);
        const firstAvailableMonth = new Date(`${availableDates[0]}T00:00:00`);
        const lastAvailableMonth = new Date(`${availableDates[availableDates.length - 1]}T00:00:00`);
        const currentMonthNumber = currentYear * 12 + currentMonth;
        const firstMonthNumber = firstAvailableMonth.getFullYear() * 12 + firstAvailableMonth.getMonth();
        const lastMonthNumber = lastAvailableMonth.getFullYear() * 12 + lastAvailableMonth.getMonth();
        const prevBtn = document.createElement('button');
        prevBtn.type = 'button';
        prevBtn.textContent = '‹';
        prevBtn.className = 'rrze-appointment__calendar-nav';
        prevBtn.dataset.direction = 'previous';
        prevBtn.disabled = currentMonthNumber <= firstMonthNumber;
        prevBtn.setAttribute('aria-label', `${i18n.previousMonth || 'Previous month'}: ${formatMonthTitle(previousMonth)}`);
        prevBtn.addEventListener('click', () => {
          currentYear = previousMonth.getFullYear();
          currentMonth = previousMonth.getMonth();
          renderCalendar('previous');
        });
        const nextBtn = document.createElement('button');
        nextBtn.type = 'button';
        nextBtn.textContent = '›';
        nextBtn.className = 'rrze-appointment__calendar-nav';
        nextBtn.dataset.direction = 'next';
        nextBtn.disabled = currentMonthNumber >= lastMonthNumber;
        nextBtn.setAttribute('aria-label', `${i18n.nextMonth || 'Next month'}: ${formatMonthTitle(nextMonth)}`);
        nextBtn.addEventListener('click', () => {
          currentYear = nextMonth.getFullYear();
          currentMonth = nextMonth.getMonth();
          renderCalendar('next');
        });
        const titleText = document.createElement('span');
        titleText.className = 'rrze-appointment__calendar-month-title';
        titleText.id = `${instanceId}-month-title`;
        titleText.tabIndex = -1;
        titleText.setAttribute('role', 'status');
        titleText.setAttribute('aria-live', 'polite');
        titleText.setAttribute('aria-atomic', 'true');
        titleText.textContent = formatMonthTitle(monthDate);
        titleRow.appendChild(prevBtn);
        titleRow.appendChild(titleText);
        titleRow.appendChild(nextBtn);
        monthWrapper.appendChild(titleRow);
        const grid = document.createElement('div');
        grid.className = ['rrze-appointment__calendar-grid', hideWeekends ? 'is-hide-weekends' : ''].filter(Boolean).join(' ');
        const today = new Date();
        const todayStr = toDateString(today.getFullYear(), today.getMonth(), today.getDate());
        function appendDayButton(day, dateString) {
          const dayOfWeek = new Date(year, monthIndex, day).getDay();
          const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
          const isPast = dateString < todayStr;
          const isToday = dateString === todayStr;
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'rrze-appointment__calendar-day';
          button.dataset.date = dateString;
          if (isWeekend || isPast) {
            button.classList.add('is-past');
          }
          if (isWeekend) {
            button.classList.add('is-weekend');
          }
          if (isToday) {
            button.classList.add('is-today');
            button.setAttribute('aria-current', 'date');
          }
          button.textContent = String(day);
          let isAvailable = false;
          if (dateSet.has(dateString)) {
            const dateSlots = dateMap.get(dateString) || [];
            const allBooked = dateSlots.length > 0 && dateSlots.every(s => bookedSlots.has(s.value) || isSlotInPast(s.value) || isSlotCutoff(s.value));
            if (!allBooked) {
              button.classList.add('is-available');
              button.setAttribute('aria-pressed', 'false');
              isAvailable = true;
            }
            if (allBooked) {
              button.classList.add('is-booked');
            }
            if (dateString === activeDate && !allBooked) {
              button.classList.add('is-active');
              button.setAttribute('aria-pressed', 'true');
            }
            if (!allBooked) {
              button.setAttribute('aria-controls', daySlotsListElement.id);
              button.addEventListener('click', () => {
                activeDate = dateString;
                renderCalendar();
                renderDaySlots(activeDate, true);
              });
            } else {
              button.disabled = true;
            }
          } else {
            button.disabled = true;
          }
          const accessibleName = [(0,_utils__WEBPACK_IMPORTED_MODULE_0__.formatDateLongDisplay)(dateString, frontendLocale), isToday ? i18n.today || 'today' : '', dateString === activeDate && isAvailable ? i18n.selected || 'selected' : '', isAvailable ? i18n.available || 'available appointments' : i18n.unavailable || 'no available appointments'].filter(Boolean);
          button.setAttribute('aria-label', accessibleName.join(', '));
          grid.appendChild(button);
        }
        if (hideWeekends) {
          weekdays.slice(0, 5).forEach(weekday => {
            const cell = document.createElement('div');
            cell.className = 'rrze-appointment__weekday';
            cell.textContent = weekday.short;
            cell.setAttribute('aria-hidden', 'true');
            cell.title = weekday.long;
            grid.appendChild(cell);
          });
          (0,_utils__WEBPACK_IMPORTED_MODULE_0__.getWeekdayMonthGridCells)(year, monthIndex).forEach(cell => {
            if (cell.type === 'empty') {
              const empty = document.createElement('div');
              empty.className = 'rrze-appointment__calendar-empty';
              grid.appendChild(empty);
              return;
            }
            appendDayButton(cell.day, cell.dateString);
          });
        } else {
          weekdays.forEach(weekday => {
            const cell = document.createElement('div');
            cell.className = 'rrze-appointment__weekday';
            cell.textContent = weekday.short;
            cell.setAttribute('aria-hidden', 'true');
            cell.title = weekday.long;
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
            appendDayButton(day, dateString);
          }
        }
        monthWrapper.appendChild(grid);
        calendarElement.appendChild(monthWrapper);
        if (focusNavigation) {
          titleText.focus();
        }
      }
      renderCalendar();
      renderDaySlots(activeDate);

      // Nach SSO-Login: Slot aus sessionStorage lesen und Overlay automatisch öffnen
      const autoSlot = sessionStorage.getItem('rrze_appt_slot');
      const autoPage = sessionStorage.getItem('rrze_appt_page');
      const onCorrectPage = !autoPage || autoPage === window.location.href.split('#')[0];
      if (autoSlot && onCorrectPage && !disableSso) {
        sessionStorage.removeItem('rrze_appt_slot');
        sessionStorage.removeItem('rrze_appt_page');
        const autoSlotDate = parseSlotValue(autoSlot).date;
        if (autoSlotDate && dateMap.has(autoSlotDate)) {
          const autoDate = new Date(`${autoSlotDate}T00:00:00`);
          activeDate = autoSlotDate;
          currentYear = autoDate.getFullYear();
          currentMonth = autoDate.getMonth();
          renderCalendar();
          renderDaySlots(activeDate);
        }
        form.setAttribute('aria-busy', 'true');
        setInteractionStatus(i18n.bookingDetailsLoading || 'Loading booking details…');
        // Booker-Daten holen und Overlay öffnen
        const data = new FormData();
        data.append('action', 'rrze_appointment_get_booker');
        fetch(window.rrze_appointment?.ajaxUrl || '/wp-admin/admin-ajax.php', {
          method: 'POST',
          body: data
        }).then(r => r.json()).then(res => {
          form.removeAttribute('aria-busy');
          setInteractionStatus();
          const booker = res.success ? res.data || {} : {};
          openOverlay(autoSlot, booker);
        }).catch(() => {
          form.removeAttribute('aria-busy');
          setInteractionStatus();
          openOverlay(autoSlot, {});
        });
      }
    }
    document.querySelectorAll('form.rrze-appointment').forEach((form, index) => {
      initAppointmentForm(form, `rrze-appt-${index + 1}`);
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
})();

/******/ })()
;
//# sourceMappingURL=view.js.map