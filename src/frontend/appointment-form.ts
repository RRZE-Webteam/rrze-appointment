import type { Booker, FrontendSlot } from './types';
import { loadCurrentBooker, requestBooker } from './api';
import { openBookingDialog } from './booking-dialog';
import { buildSlotsByDate, parseSlotValue } from './slot-parser';
import {
	isBookingClosed,
	isBookingNotOpen,
} from '../scheduling/booking-window';
import {
	formatDateDisplay,
	formatDateLongDisplay,
	getWorkweekMonthGridCells,
} from '../scheduling/dates';
import {
	formatMonthTitle,
	getWeekdayLabels,
	resolveFrontendLocale,
	toDateString,
} from './calendar';

export function initializeAppointmentForms(): void {
	const i18n = window.rrze_appointment?.i18n || {};
	const frontendLocale = resolveFrontendLocale();
	const weekdays = getWeekdayLabels( frontendLocale );

	function initializeAppointmentForm(
		form: HTMLFormElement,
		instanceId: string
	): void {
		const calendar = form.querySelector< HTMLElement >(
			'.rrze-appointment__calendar'
		);
		const daySlotsContainer = form.querySelector< HTMLElement >(
			'.rrze-appointment__day-slots'
		);
		const daySlotsList = form.querySelector< HTMLElement >(
			'.rrze-appointment__day-slots-list'
		);
		const slotData = form.querySelector< HTMLElement >(
			'.rrze-appointment__slot-data'
		);
		if (
			! calendar ||
			! daySlotsContainer ||
			! daySlotsList ||
			! slotData
		) {
			return;
		}

		const calendarElement = calendar;
		const daySlotsContainerElement = daySlotsContainer;
		const daySlotsListElement = daySlotsList;
		const slotDataElement = slotData;
		const availabilityStatusElement = form.querySelector< HTMLElement >(
			'.rrze-appointment__availability-status'
		);
		const selectedInfoElement = form.querySelector< HTMLElement >(
			'.rrze-appointment__selected-info'
		);
		daySlotsListElement.id = `${ instanceId }-slots`;

		calendarElement.setAttribute( 'role', 'group' );
		calendarElement.setAttribute(
			'aria-label',
			i18n.chooseDate || 'Choose an appointment date'
		);

		function showAvailabilityMessage( message: string ): void {
			if ( ! availabilityStatusElement ) {
				return;
			}
			availabilityStatusElement.textContent = message;
			availabilityStatusElement.classList.remove( 'is-hidden' );
		}

		function clearAvailabilityMessage(): void {
			if ( ! availabilityStatusElement ) {
				return;
			}
			availabilityStatusElement.textContent = '';
			availabilityStatusElement.classList.add( 'is-hidden' );
		}

		function setInteractionStatus( message = '' ): void {
			if ( ! selectedInfoElement ) {
				return;
			}
			selectedInfoElement.textContent = message;
			selectedInfoElement.classList.toggle( 'is-hidden', ! message );
		}

		const slotInputs = Array.from(
			slotDataElement.querySelectorAll< HTMLInputElement >(
				'input[name="rrze_appointment_slot"]'
			)
		);
		if ( slotInputs.length === 0 ) {
			return;
		}

		const slotsByDate = buildSlotsByDate( slotInputs );
		let availableDates: string[] = [];
		let availableDateSet = new Set< string >();

		let activeDate = '';
		let selectedSlotValue = '';

		const bookedSlots = new Set< string >(
			window.rrze_appointment?.bookedSlots || []
		);
		const bookingCutoffMinutes = parseInt(
			form.dataset.bookingCutoff || '0',
			10
		);
		const bookingMaxAdvanceMinutes = parseInt(
			form.dataset.bookingMaxAdvance || '0',
			10
		);
		const allowBookingsWithoutSso = form.dataset.disableSso === '1';
		const hideWeekends = form.dataset.hideWeekends === '1';

		function parseSlotStart( slotValue: string ): Date | null {
			const parsedSlot = parseSlotValue( slotValue );
			if ( ! parsedSlot.date || ! parsedSlot.time ) {
				return null;
			}
			const slotStart = new Date(
				`${ parsedSlot.date }T${ parsedSlot.time }:00`
			);
			return Number.isNaN( slotStart.getTime() ) ? null : slotStart;
		}

		function isSlotInPast( slotValue: string ): boolean {
			const slotStart = parseSlotStart( slotValue );
			if ( ! slotStart ) {
				return false;
			}
			return slotStart <= new Date();
		}

		function isSlotPastBookingCutoff( slotValue: string ): boolean {
			const slotStart = parseSlotStart( slotValue );
			if ( ! slotStart ) {
				return false;
			}
			return isBookingClosed(
				slotStart,
				new Date(),
				bookingCutoffMinutes
			);
		}

		function isSlotTooFarInAdvance( slotValue: string ): boolean {
			const slotStart = parseSlotStart( slotValue );
			if ( ! slotStart ) {
				return false;
			}
			return isBookingNotOpen(
				slotStart,
				new Date(),
				bookingMaxAdvanceMinutes
			);
		}

		function isSlotUnavailable( slotValue: string ): boolean {
			return (
				bookedSlots.has( slotValue ) ||
				isSlotInPast( slotValue ) ||
				isSlotPastBookingCutoff( slotValue )
			);
		}

		function getBookingAdvanceMessage(): string {
			const days = Math.max(
				1,
				Math.ceil( bookingMaxAdvanceMinutes / ( 24 * 60 ) )
			);
			const template =
				days === 1
					? i18n.bookingAdvanceDay ||
					  'These appointments can only be booked %d day in advance.'
					: i18n.bookingAdvanceDays ||
					  'These appointments can only be booked %d days in advance.';
			return template.replace( '%d', String( days ) );
		}

		function refreshAvailabilityMessage(): void {
			const hasAvailableSlot = Array.from( slotsByDate.values() ).some(
				( slots ) =>
					slots.some( ( slot ) => ! isSlotUnavailable( slot.value ) )
			);
			if ( hasAvailableSlot ) {
				clearAvailabilityMessage();
				return;
			}
			showAvailabilityMessage(
				i18n.noSlotsAvailable || 'No time slots available.'
			);
		}

		slotsByDate.forEach( ( slots, date ) => {
			const availableSlots = slots.filter(
				( slot ) => ! isSlotUnavailable( slot.value )
			);
			if ( availableSlots.length > 0 ) {
				slotsByDate.set( date, availableSlots );
			} else {
				slotsByDate.delete( date );
			}
		} );

		availableDates = Array.from( slotsByDate.keys() ).sort();
		if ( availableDates.length === 0 ) {
			calendarElement.innerHTML = '';
			daySlotsContainerElement.classList.add( 'is-hidden' );
			showAvailabilityMessage(
				i18n.noSlotsAvailable || 'No time slots available.'
			);
			return;
		}
		clearAvailabilityMessage();
		availableDateSet = new Set( availableDates );
		const firstDate = new Date( `${ availableDates[ 0 ] }T00:00:00` );
		activeDate = availableDates[ 0 ];
		let currentYear = firstDate.getFullYear();
		let currentMonth = firstDate.getMonth();

		function selectHiddenSlotInput( slotValue: string ): void {
			slotInputs.forEach( ( input ) => {
				input.checked = input.value === slotValue;
			} );
		}

		function showBookingDialog(
			slotValue: string,
			booker: Booker = {},
			triggerButton: HTMLButtonElement | null = null
		): void {
			openBookingDialog( {
				booker,
				form,
				frontendLocale,
				i18n,
				instanceId,
				slotValue,
				triggerButton,
				isSlotTooFarInAdvance,
				isSlotUnavailable,
				onBookingSucceeded: ( bookedSlotValue ) => {
					bookedSlots.add( bookedSlotValue );
					renderCalendar();
					renderDaySlots( activeDate );
					refreshAvailabilityMessage();
				},
				onSlotSelected: ( selectedValue ) => {
					selectedSlotValue = selectedValue;
					selectHiddenSlotInput( selectedValue );
				},
			} );
		}

		function createSlotButton( slot: FrontendSlot ): HTMLButtonElement {
			const isUnavailable = isSlotUnavailable( slot.value );
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'rrze-appointment__slot-button';
			button.textContent = slot.label;
			button.dataset.slotValue = slot.value;
			const slotDate = parseSlotValue( slot.value ).date;
			if ( slotDate ) {
				button.setAttribute(
					'aria-label',
					`${ slot.label }, ${ formatDateLongDisplay(
						slotDate,
						frontendLocale
					) }`
				);
			}

			if ( isUnavailable ) {
				button.classList.add( 'is-booked' );
				button.disabled = true;
				return button;
			}
			const isBookingNotOpenYet = isSlotTooFarInAdvance( slot.value );
			if ( isBookingNotOpenYet ) {
				const bookingAdvanceMessage = getBookingAdvanceMessage();
				button.classList.add( 'is-not-open' );
				button.title = bookingAdvanceMessage;
				button.setAttribute(
					'aria-label',
					`${
						button.getAttribute( 'aria-label' ) || slot.label
					}, ${ bookingAdvanceMessage }`
				);
			}

			if ( selectedSlotValue && slot.value === selectedSlotValue ) {
				button.classList.add( 'is-active' );
			}

			button.addEventListener( 'click', () => {
				if ( allowBookingsWithoutSso ) {
					showBookingDialog( slot.value, {}, button );
					return;
				}

				button.disabled = true;
				button.setAttribute( 'aria-busy', 'true' );
				form.setAttribute( 'aria-busy', 'true' );
				setInteractionStatus(
					i18n.bookingDetailsLoading || 'Loading booking details…'
				);

				requestBooker( window.location.href.split( '#' )[ 0 ] )
					.then( ( bookerResponse ) => {
						button.disabled = false;
						button.removeAttribute( 'aria-busy' );
						form.removeAttribute( 'aria-busy' );
						setInteractionStatus();

						if ( ! bookerResponse ) {
							return;
						}

						if ( bookerResponse.needsLogin ) {
							const loginUrl = (
								bookerResponse.loginUrl || ''
							).trim();
							if ( ! loginUrl ) {
								return;
							}
							sessionStorage.setItem(
								'rrze_appt_slot',
								slot.value
							);
							sessionStorage.setItem(
								'rrze_appt_page',
								window.location.href.split( '#' )[ 0 ]
							);
							window.location.href = loginUrl;
							return;
						}

						const booker = bookerResponse.data || {};

						showBookingDialog( slot.value, booker, button );
					} )
					.catch( () => {
						button.disabled = false;
						button.removeAttribute( 'aria-busy' );
						form.removeAttribute( 'aria-busy' );
						setInteractionStatus();

						showBookingDialog( slot.value, {}, button );
					} );
			} );
			return button;
		}

		function renderDaySlots( date: string, focusTitle = false ): void {
			const slots = ( slotsByDate.get( date ) || [] ).filter(
				( slot ) => ! isSlotUnavailable( slot.value )
			);
			daySlotsListElement.innerHTML = '';
			daySlotsListElement.className =
				'rrze-appointment__day-slots-list rrze-appointment__slot-grid';

			if ( slots.length === 0 ) {
				daySlotsContainerElement.classList.add( 'is-hidden' );
				return;
			}
			const hasNotOpenSlots = slots.some( ( slot ) =>
				isSlotTooFarInAdvance( slot.value )
			);
			const hasBookableSlots = slots.some(
				( slot ) => ! isSlotTooFarInAdvance( slot.value )
			);

			daySlotsContainerElement.classList.remove( 'is-hidden' );
			const slotsTitleElement = daySlotsContainerElement.querySelector(
				'.rrze-appointment__day-slots-title'
			);
			if ( slotsTitleElement ) {
				slotsTitleElement.textContent = (
					hasBookableSlots
						? i18n.availableOn || 'Available appointments on %s'
						: i18n.appointmentsOn || 'Appointments on %s'
				).replace( '%s', formatDateDisplay( date, frontendLocale ) );
				( slotsTitleElement as HTMLElement ).tabIndex = -1;
			}

			const existingNotice = daySlotsContainerElement.querySelector(
				'.rrze-appointment__booking-window-notice'
			);
			existingNotice?.remove();
			if ( hasNotOpenSlots ) {
				const bookingWindowNotice = document.createElement( 'p' );
				bookingWindowNotice.className =
					'rrze-appointment__booking-window-notice';
				bookingWindowNotice.textContent = getBookingAdvanceMessage();
				daySlotsListElement.before( bookingWindowNotice );
			}

			slots.forEach( ( slot ) => {
				daySlotsListElement.appendChild( createSlotButton( slot ) );
			} );

			if ( focusTitle && slotsTitleElement instanceof HTMLElement ) {
				slotsTitleElement.focus();
			}
		}

		function renderCalendar(
			focusNavigation: 'previous' | 'next' | null = null
		): void {
			calendarElement.innerHTML = '';

			const monthDate = new Date( currentYear, currentMonth, 1 );
			const year = monthDate.getFullYear();
			const monthIndex = monthDate.getMonth();

			const monthWrapper = document.createElement( 'div' );
			monthWrapper.className = 'rrze-appointment__calendar-month';

			const titleRow = document.createElement( 'div' );
			titleRow.className = 'rrze-appointment__calendar-title';
			const previousMonth = new Date( currentYear, currentMonth - 1, 1 );
			const nextMonth = new Date( currentYear, currentMonth + 1, 1 );
			const firstAvailableMonth = new Date(
				`${ availableDates[ 0 ] }T00:00:00`
			);
			const lastAvailableMonth = new Date(
				`${ availableDates[ availableDates.length - 1 ] }T00:00:00`
			);
			const currentMonthNumber = currentYear * 12 + currentMonth;
			const firstMonthNumber =
				firstAvailableMonth.getFullYear() * 12 +
				firstAvailableMonth.getMonth();
			const lastMonthNumber =
				lastAvailableMonth.getFullYear() * 12 +
				lastAvailableMonth.getMonth();

			const previousMonthButton = document.createElement( 'button' );
			previousMonthButton.type = 'button';
			previousMonthButton.textContent = '‹';
			previousMonthButton.className = 'rrze-appointment__calendar-nav';
			previousMonthButton.dataset.direction = 'previous';
			previousMonthButton.disabled =
				currentMonthNumber <= firstMonthNumber;
			previousMonthButton.setAttribute(
				'aria-label',
				`${
					i18n.previousMonth || 'Previous month'
				}: ${ formatMonthTitle( previousMonth, frontendLocale ) }`
			);
			previousMonthButton.addEventListener( 'click', () => {
				currentYear = previousMonth.getFullYear();
				currentMonth = previousMonth.getMonth();
				renderCalendar( 'previous' );
			} );

			const nextMonthButton = document.createElement( 'button' );
			nextMonthButton.type = 'button';
			nextMonthButton.textContent = '›';
			nextMonthButton.className = 'rrze-appointment__calendar-nav';
			nextMonthButton.dataset.direction = 'next';
			nextMonthButton.disabled = currentMonthNumber >= lastMonthNumber;
			nextMonthButton.setAttribute(
				'aria-label',
				`${ i18n.nextMonth || 'Next month' }: ${ formatMonthTitle(
					nextMonth,
					frontendLocale
				) }`
			);
			nextMonthButton.addEventListener( 'click', () => {
				currentYear = nextMonth.getFullYear();
				currentMonth = nextMonth.getMonth();
				renderCalendar( 'next' );
			} );

			const titleText = document.createElement( 'span' );
			titleText.className = 'rrze-appointment__calendar-month-title';
			titleText.id = `${ instanceId }-month-title`;
			titleText.tabIndex = -1;
			titleText.setAttribute( 'role', 'status' );
			titleText.setAttribute( 'aria-live', 'polite' );
			titleText.setAttribute( 'aria-atomic', 'true' );
			titleText.textContent = formatMonthTitle(
				monthDate,
				frontendLocale
			);

			titleRow.appendChild( previousMonthButton );
			titleRow.appendChild( titleText );
			titleRow.appendChild( nextMonthButton );
			monthWrapper.appendChild( titleRow );

			const grid = document.createElement( 'div' );
			grid.className = [
				'rrze-appointment__calendar-grid',
				hideWeekends ? 'is-hide-weekends' : '',
			]
				.filter( Boolean )
				.join( ' ' );

			const today = new Date();
			const todayDateString = toDateString(
				today.getFullYear(),
				today.getMonth(),
				today.getDate()
			);

			function appendDayButton( day: number, dateString: string ): void {
				const dayOfWeek = new Date( year, monthIndex, day ).getDay();
				const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
				const isPast = dateString < todayDateString;
				const isToday = dateString === todayDateString;
				const button = document.createElement( 'button' );

				button.type = 'button';
				button.className = 'rrze-appointment__calendar-day';
				button.dataset.date = dateString;
				if ( isWeekend || isPast ) {
					button.classList.add( 'is-past' );
				}
				if ( isWeekend ) {
					button.classList.add( 'is-weekend' );
				}
				if ( isToday ) {
					button.classList.add( 'is-today' );
					button.setAttribute( 'aria-current', 'date' );
				}
				button.textContent = String( day );
				let isAvailable = false;

				if ( availableDateSet.has( dateString ) ) {
					const dateSlots = slotsByDate.get( dateString ) || [];
					const hasAvailable = dateSlots.some(
						( slot ) =>
							! isSlotUnavailable( slot.value ) &&
							! isSlotTooFarInAdvance( slot.value )
					);
					const hasNotOpen = dateSlots.some(
						( slot ) =>
							! isSlotUnavailable( slot.value ) &&
							isSlotTooFarInAdvance( slot.value )
					);
					const isSelectable = hasAvailable || hasNotOpen;

					if ( hasAvailable ) {
						button.classList.add( 'is-available' );
						button.setAttribute( 'aria-pressed', 'false' );
						isAvailable = true;
					}
					if ( hasNotOpen && ! hasAvailable ) {
						button.classList.add( 'is-not-open' );
						button.setAttribute( 'aria-pressed', 'false' );
					}
					if ( ! isSelectable ) {
						button.classList.add( 'is-booked' );
					}
					if ( dateString === activeDate && isSelectable ) {
						button.classList.add( 'is-active' );
						button.setAttribute( 'aria-pressed', 'true' );
					}

					if ( isSelectable ) {
						button.setAttribute(
							'aria-controls',
							daySlotsListElement.id
						);
						button.addEventListener( 'click', () => {
							activeDate = dateString;
							renderCalendar();
							renderDaySlots( activeDate, true );
						} );
					} else {
						button.disabled = true;
					}
				} else {
					button.disabled = true;
				}

				let availabilityLabel =
					i18n.unavailable || 'no available appointments';
				if ( isAvailable ) {
					availabilityLabel =
						i18n.available || 'available appointments';
				}
				if ( button.classList.contains( 'is-not-open' ) ) {
					availabilityLabel = `${
						i18n.notOpen || 'appointments not yet bookable'
					}, ${ getBookingAdvanceMessage() }`;
				}

				const accessibleName = [
					formatDateLongDisplay( dateString, frontendLocale ),
					isToday ? i18n.today || 'today' : '',
					dateString === activeDate && isAvailable
						? i18n.selected || 'selected'
						: '',
					availabilityLabel,
				].filter( Boolean );
				button.setAttribute(
					'aria-label',
					accessibleName.join( ', ' )
				);

				grid.appendChild( button );
			}

			if ( hideWeekends ) {
				weekdays.slice( 0, 5 ).forEach( ( weekday ) => {
					const cell = document.createElement( 'div' );
					cell.className = 'rrze-appointment__weekday';
					cell.textContent = weekday.short;
					cell.setAttribute( 'aria-hidden', 'true' );
					cell.title = weekday.long;
					grid.appendChild( cell );
				} );

				getWorkweekMonthGridCells( year, monthIndex ).forEach(
					( cell ) => {
						if ( cell.type === 'empty' ) {
							const empty = document.createElement( 'div' );
							empty.className =
								'rrze-appointment__calendar-empty';
							grid.appendChild( empty );
							return;
						}
						appendDayButton( cell.day, cell.dateString );
					}
				);
			} else {
				weekdays.forEach( ( weekday ) => {
					const cell = document.createElement( 'div' );
					cell.className = 'rrze-appointment__weekday';
					cell.textContent = weekday.short;
					cell.setAttribute( 'aria-hidden', 'true' );
					cell.title = weekday.long;
					grid.appendChild( cell );
				} );

				const firstWeekdayIndex =
					( new Date( year, monthIndex, 1 ).getDay() + 6 ) % 7;
				const daysInMonth = new Date(
					year,
					monthIndex + 1,
					0
				).getDate();

				for (
					let emptyCellIndex = 0;
					emptyCellIndex < firstWeekdayIndex;
					emptyCellIndex += 1
				) {
					const empty = document.createElement( 'div' );
					empty.className = 'rrze-appointment__calendar-empty';
					grid.appendChild( empty );
				}

				for ( let day = 1; day <= daysInMonth; day += 1 ) {
					const dateString = toDateString( year, monthIndex, day );
					appendDayButton( day, dateString );
				}
			}

			monthWrapper.appendChild( grid );
			calendarElement.appendChild( monthWrapper );

			if ( focusNavigation ) {
				titleText.focus();
			}
		}

		renderCalendar();
		renderDaySlots( activeDate );

		const storedSlotValue = sessionStorage.getItem( 'rrze_appt_slot' );
		const storedPageUrl = sessionStorage.getItem( 'rrze_appt_page' );
		const isStoredBookingPage =
			! storedPageUrl ||
			storedPageUrl === window.location.href.split( '#' )[ 0 ];
		if (
			storedSlotValue &&
			isStoredBookingPage &&
			! allowBookingsWithoutSso
		) {
			sessionStorage.removeItem( 'rrze_appt_slot' );
			sessionStorage.removeItem( 'rrze_appt_page' );

			const storedSlotDate = parseSlotValue( storedSlotValue ).date;
			if ( storedSlotDate && slotsByDate.has( storedSlotDate ) ) {
				const storedAppointmentDate = new Date(
					`${ storedSlotDate }T00:00:00`
				);
				activeDate = storedSlotDate;
				currentYear = storedAppointmentDate.getFullYear();
				currentMonth = storedAppointmentDate.getMonth();
				renderCalendar();
				renderDaySlots( activeDate );
			}

			form.setAttribute( 'aria-busy', 'true' );
			setInteractionStatus(
				i18n.bookingDetailsLoading || 'Loading booking details…'
			);
			loadCurrentBooker()
				.then( ( booker ) => {
					form.removeAttribute( 'aria-busy' );
					setInteractionStatus();
					showBookingDialog( storedSlotValue, booker );
				} )
				.catch( () => {
					form.removeAttribute( 'aria-busy' );
					setInteractionStatus();
					showBookingDialog( storedSlotValue, {} );
				} );
		}
	}

	document
		.querySelectorAll< HTMLFormElement >( 'form.rrze-appointment' )
		.forEach( ( form, index ) => {
			initializeAppointmentForm( form, `rrze-appt-${ index + 1 }` );
		} );
}
