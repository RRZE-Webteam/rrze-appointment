import type { Booker, FrontendSlot } from './types';
import { bookerErrorMessage, loadCurrentBooker, requestBooker } from './api';
import { openBookingDialog } from './booking-dialog';
import { parseSlotValue } from './slot-parser';
import { MonthSchedule } from './month-schedule';
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

/** Attaches the calendar, list navigation and booking controls to each rendered block. */
export function initializeAppointmentForms(): void {
	const i18n = window.rrze_appointment?.i18n || {};
	const frontendLocale = resolveFrontendLocale();
	const weekdays = getWeekdayLabels( frontendLocale );

	/**
	 * Initializes independent selection and navigation state for this form.
	 *
	 * @param form       Published appointment form.
	 * @param instanceId Unique namespace for accessible control IDs.
	 */
	function initializeAppointmentForm(
		form: HTMLFormElement,
		instanceId: string
	): void {
		const showSlotsAsList = form.dataset.showSlotsAsList === '1';
		const readOnly = form.dataset.readOnly === '1';
		const dateList = form.querySelector< HTMLElement >(
			'.rrze-appointment__date-list'
		);
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
			! slotData ||
			( showSlotsAsList
				? ! dateList
				: ! calendar || ! daySlotsContainer || ! daySlotsList )
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
		if ( daySlotsListElement ) {
			daySlotsListElement.id = `${ instanceId }-slots`;
		}

		calendarElement?.setAttribute( 'role', 'group' );
		calendarElement?.setAttribute(
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

		const schedule = new MonthSchedule( form, slotDataElement );
		const slotsByDate = schedule.dates;
		const months = schedule.months;
		if ( ! months.length ) {
			return;
		}
		const moreButton = form.querySelector< HTMLButtonElement >(
			'.rrze-appointment__more'
		);
		const retryButton = form.querySelector< HTMLButtonElement >(
			'.rrze-appointment__retry'
		);
		const listMonths = new Set( schedule.paged ? [ months[ 0 ] ] : months );
		let availableDates: string[] = [];
		let availableDateSet = new Set< string >();
		let currentMonthIndex = 0;
		let requestedIndex = 0;
		let navigationVersion = 0;
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

		/**
		 * Resolves a slot's absolute start, including times from cached radio markup.
		 * @param slotValue Canonical appointment identifier.
		 * @return Instant used to check the booking window.
		 */
		function parseSlotStart( slotValue: string ): Date | null {
			const timestamp = schedule.timestamps.get( slotValue );
			if ( timestamp !== undefined ) {
				return new Date( timestamp * 1000 );
			}
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

		/**
		 * Checks the opening window for interactive booking controls.
		 * @param slotValue Canonical appointment identifier.
		 * @return Whether the visitor must wait before booking.
		 */
		function isSlotTooFarInAdvance( slotValue: string ): boolean {
			const slotStart = parseSlotStart( slotValue );
			if ( readOnly || ! slotStart ) {
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

		/** Includes only visible dates in the loaded portion of the schedule. */
		function refreshDates(): void {
			availableDates = Array.from( slotsByDate.keys() )
				.filter(
					( date ) =>
						slotsByDate
							.get( date )
							?.some(
								( slot ) => ! isSlotUnavailable( slot.value )
							)
				)
				.sort();
			availableDateSet = new Set( availableDates );
		}

		/**
		 * Keeps one selection value for submission and supports radio markup from cached pages.
		 *
		 * @param slotValue Selected canonical appointment identifier.
		 */
		function selectHiddenSlotInput( slotValue: string ): void {
			slotDataElement
				.querySelectorAll< HTMLInputElement >(
					'input[name="rrze_appointment_slot"]'
				)
				.forEach( ( input ) => {
					if ( input.type === 'hidden' ) {
						input.value = slotValue;
					} else {
						input.checked = input.value === slotValue;
					}
				} );
		}

		refreshDates();
		activeDate = availableDates[ 0 ] || '';
		if ( activeDate ) {
			currentMonthIndex = months.indexOf( activeDate.slice( 0, 7 ) );
			requestedIndex = currentMonthIndex;
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
					renderAvailability();
				},
				onSlotSelected: ( selectedValue ) => {
					selectedSlotValue = selectedValue;
					selectHiddenSlotInput( selectedValue );
				},
			} );
		}

		/**
		 * Builds the time button that starts a guest booking or restores SSO on return.
		 * @param slot Display name and canonical appointment value.
		 * @return Interactive appointment button.
		 */
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

						if ( bookerResponse.needsLogin ) {
							const loginUrl = (
								bookerResponse.loginUrl || ''
							).trim();
							if ( ! loginUrl ) {
								return;
							}
							sessionStorage.setItem(
								'rrze_appt_block',
								form.dataset.blockId || ''
							);
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
						setInteractionStatus( bookerErrorMessage() );
					} );
			} );
			return button;
		}

		/**
		 * Displays a day's appointments as booking controls or read-only times.
		 * @param date       Selected ISO date.
		 * @param container  The group containing its heading and times.
		 * @param list       Element that receives the times.
		 * @param focusTitle Whether to focus the heading after selecting a day.
		 */
		function renderDateSlots(
			date: string,
			container: HTMLElement,
			list: HTMLElement,
			focusTitle = false
		): void {
			const slots = ( slotsByDate.get( date ) || [] ).filter(
				( slot ) => ! isSlotUnavailable( slot.value )
			);
			list.innerHTML = '';
			list.className =
				'rrze-appointment__day-slots-list rrze-appointment__slot-grid';

			if ( slots.length === 0 ) {
				container.classList.add( 'is-hidden' );
				return;
			}
			const hasNotOpenSlots = slots.some( ( slot ) =>
				isSlotTooFarInAdvance( slot.value )
			);
			const hasBookableSlots = slots.some(
				( slot ) => ! isSlotTooFarInAdvance( slot.value )
			);

			container.classList.remove( 'is-hidden' );
			const slotsTitleElement = container.querySelector< HTMLElement >(
				'.rrze-appointment__day-slots-title'
			);
			if ( slotsTitleElement ) {
				slotsTitleElement.textContent = (
					hasBookableSlots
						? i18n.availableOn || 'Available appointments on %s'
						: i18n.appointmentsOn || 'Appointments on %s'
				).replace( '%s', formatDateDisplay( date, frontendLocale ) );
				slotsTitleElement.tabIndex = -1;
			}

			const existingNotice = container.querySelector(
				'.rrze-appointment__booking-window-notice'
			);
			existingNotice?.remove();
			if ( hasNotOpenSlots ) {
				const bookingWindowNotice = document.createElement( 'p' );
				bookingWindowNotice.className =
					'rrze-appointment__booking-window-notice';
				bookingWindowNotice.textContent = getBookingAdvanceMessage();
				list.before( bookingWindowNotice );
			}

			slots.forEach( ( slot ) => {
				if ( readOnly ) {
					const time = document.createElement( 'span' );
					time.textContent = slot.label;
					list.appendChild( time );
				} else {
					list.appendChild( createSlotButton( slot ) );
				}
			} );

			if ( focusTitle && slotsTitleElement instanceof HTMLElement ) {
				slotsTitleElement.focus();
			}
		}

		function renderDaySlots( date: string, focusTitle = false ): void {
			if ( daySlotsContainerElement && daySlotsListElement ) {
				renderDateSlots(
					date,
					daySlotsContainerElement,
					daySlotsListElement,
					focusTitle
				);
			}
		}

		/** Displays the loaded list months in chronological, labeled date groups. */
		function renderDateList(): void {
			if ( ! dateList ) {
				return;
			}
			const dateGroups = document.createDocumentFragment();
			availableDates
				.filter( ( date ) => listMonths.has( date.slice( 0, 7 ) ) )
				.forEach( ( date ) => {
					const slots = slotsByDate.get( date ) || [];
					if (
						! slots.some(
							( slot ) => ! isSlotUnavailable( slot.value )
						)
					) {
						return;
					}
					const section = document.createElement( 'section' );
					section.className = 'rrze-appointment__day-slots';
					section.dataset.date = date;
					const heading = document.createElement( 'h3' );
					heading.className = 'rrze-appointment__day-slots-title';
					heading.id = `${ instanceId }-date-${ date }`;
					section.setAttribute( 'aria-labelledby', heading.id );
					const list = document.createElement( 'div' );
					section.append( heading, list );
					renderDateSlots( date, section, list );
					dateGroups.appendChild( section );
				} );
			dateList.replaceChildren( dateGroups );
		}

		/**
		 * Refreshes the visible controls after bookings and completed requests.
		 * @param focusMonth Move keyboard focus to the new calendar month heading.
		 */
		function renderAvailability( focusMonth = false ): void {
			refreshDates();
			if ( showSlotsAsList ) {
				renderDateList();
				if ( moreButton ) {
					moreButton.hidden = months.every( ( month ) =>
						listMonths.has( month )
					);
				}
			} else {
				renderCalendar( focusMonth );
				renderDaySlots( activeDate );
			}
			const visible = availableDates.some( ( date ) =>
				showSlotsAsList
					? listMonths.has( date.slice( 0, 7 ) )
					: date.startsWith( months[ currentMonthIndex ] )
			);
			if ( visible ) {
				clearAvailabilityMessage();
			} else {
				showAvailabilityMessage(
					i18n.noSlotsAvailable || 'No time slots available.'
				);
			}
		}

		/**
		 * Resumes an SSO booking only while its month and appointment are still selected.
		 *
		 * @param slotValue Saved canonical appointment identifier.
		 * @param version   Navigation that initiated identity lookup.
		 */
		async function reopenBooking(
			slotValue: string,
			version: number
		): Promise< void > {
			const exists = slotsByDate
				.get( activeDate )
				?.some( ( slot ) => slot.value === slotValue );
			if ( ! exists || isSlotUnavailable( slotValue ) ) {
				setInteractionStatus(
					i18n.noSlotsAvailable || 'No time slots available.'
				);
				return;
			}
			form.setAttribute( 'aria-busy', 'true' );
			setInteractionStatus(
				i18n.bookingDetailsLoading || 'Loading booking details…'
			);
			const booker = await loadCurrentBooker();
			if ( version === navigationVersion ) {
				setInteractionStatus();
				showBookingDialog( slotValue, booker );
			}
		}

		/**
		 * Loads months until the requested navigation can be displayed.
		 * Older responses may populate the cache but cannot change the selected view.
		 *
		 * @param index     First candidate in the chronological month index.
		 * @param mode      Initial selection, calendar navigation, list extension or SSO resumption.
		 * @param slotValue Canonical appointment to restore after login.
		 */
		async function navigate(
			index: number,
			mode: 'initial' | 'calendar' | 'more' | 'restore',
			slotValue = ''
		): Promise< void > {
			const version = ++navigationVersion;
			if ( retryButton ) {
				retryButton.hidden = true;
			}
			const firstIndex = index;
			try {
				while ( index >= 0 && index < months.length ) {
					const month = months[ index ];
					requestedIndex = index;
					if ( ! schedule.has( month ) ) {
						form.setAttribute( 'aria-busy', 'true' );
						setInteractionStatus(
							i18n.loadingAppointments || 'Loading appointments…'
						);
						if ( ! showSlotsAsList ) {
							renderCalendar();
						}
						await schedule.load( month );
					}
					if ( version !== navigationVersion ) {
						return;
					}
					refreshDates();
					if ( showSlotsAsList ) {
						listMonths.add( month );
					}
					const first = availableDates.find( ( date ) =>
						date.startsWith( month )
					);
					if ( mode === 'initial' && ! first ) {
						++index;
						continue;
					}
					if ( mode === 'more' && ! first ) {
						index = months.findIndex(
							( candidate ) => ! listMonths.has( candidate )
						);
						continue;
					}
					currentMonthIndex = index;
					activeDate = first || '';
					if ( mode === 'calendar' || mode === 'restore' ) {
						activeDate = slotValue.slice( 0, 10 );
					}
					break;
				}
				renderAvailability( mode === 'calendar' );
				setInteractionStatus();
				if ( mode === 'more' && activeDate ) {
					form
						.querySelector< HTMLElement >(
							`section[data-date="${ activeDate }"] h3`
						)
						?.focus();
				}
				if ( mode === 'restore' ) {
					await reopenBooking( slotValue, version );
				}
			} catch {
				if ( version === navigationVersion ) {
					requestedIndex = currentMonthIndex;
					renderAvailability();
					setInteractionStatus(
						mode === 'restore'
							? bookerErrorMessage()
							: i18n.monthLoadError ||
									'Unable to load appointments. Please try again.'
					);
					if ( retryButton ) {
						retryButton.hidden = false;
						retryButton.onclick = () =>
							void navigate( firstIndex, mode, slotValue );
					}
				}
			} finally {
				if ( version === navigationVersion ) {
					form.removeAttribute( 'aria-busy' );
				}
			}
		}

		/**
		 * Renders the selected calendar month and neighboring navigation targets.
		 * @param focusNavigation Announce the new month to keyboard users.
		 */
		function renderCalendar( focusNavigation = false ): void {
			if ( ! calendarElement || ! daySlotsListElement ) {
				return;
			}
			const daySlotsListId = daySlotsListElement.id;
			calendarElement.innerHTML = '';
			const monthDate = new Date(
				months[ currentMonthIndex ] + '-01T00:00:00'
			);
			const year = monthDate.getFullYear();
			const monthIndex = monthDate.getMonth();

			const monthWrapper = document.createElement( 'div' );
			monthWrapper.className = 'rrze-appointment__calendar-month';

			const titleRow = document.createElement( 'div' );
			titleRow.className = 'rrze-appointment__calendar-title';
			/**
			 * Creates a labeled navigation button for a neighboring scheduled month.
			 * @param step Month index offset.
			 * @return Navigation button, disabled at the edge of the schedule.
			 */
			function monthButton( step: -1 | 1 ): HTMLButtonElement {
				const index = requestedIndex + step;
				const target = months[ index ];
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'rrze-appointment__calendar-nav';
				button.dataset.direction = step < 0 ? 'previous' : 'next';
				button.textContent = step < 0 ? '‹' : '›';
				button.disabled = ! target;
				const direction =
					step < 0
						? i18n.previousMonth || 'Previous month'
						: i18n.nextMonth || 'Next month';
				button.setAttribute(
					'aria-label',
					target
						? direction +
								': ' +
								formatMonthTitle(
									new Date( target + '-01T00:00:00' ),
									frontendLocale
								)
						: direction
				);
				button.addEventListener(
					'click',
					() => void navigate( index, 'calendar' )
				);
				return button;
			}
			const previousMonthButton = monthButton( -1 );
			const nextMonthButton = monthButton( 1 );

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

			/**
			 * Adds one day to the visible calendar and connects it to the time selection.
			 * @param day        Day number printed on the button.
			 * @param dateString ISO calendar date.
			 */
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
						button.setAttribute( 'aria-controls', daySlotsListId );
						button.addEventListener( 'click', () => {
							++navigationVersion;
							requestedIndex = currentMonthIndex;
							form.removeAttribute( 'aria-busy' );
							setInteractionStatus();
							if ( retryButton ) {
								retryButton.hidden = true;
							}
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

		moreButton?.addEventListener( 'click', () => {
			const next = months.findIndex(
				( month ) => ! listMonths.has( month )
			);
			if ( next >= 0 ) {
				void navigate( next, 'more' );
			}
		} );
		renderAvailability();
		if ( schedule.paged ) {
			slotDataElement
				.querySelectorAll( 'input[type="radio"]' )
				.forEach( ( radio ) => radio.remove() );
			form.querySelector( '.rrze-appointment__fallback-times' )?.remove();
			const links = form.querySelector< HTMLElement >(
				'.rrze-appointment__month-links'
			);
			if ( links && ! links.hasAttribute( 'data-linked-page' ) ) {
				links.hidden = true;
			}
		}

		const storedSlot = sessionStorage.getItem( 'rrze_appt_slot' );
		const storedPage = sessionStorage.getItem( 'rrze_appt_page' );
		const storedBlock = sessionStorage.getItem( 'rrze_appt_block' );
		const storedIndex = storedSlot
			? months.indexOf( storedSlot.slice( 0, 7 ) )
			: -1;
		if (
			storedSlot &&
			! readOnly &&
			storedIndex >= 0 &&
			! allowBookingsWithoutSso &&
			( ! storedPage ||
				storedPage === window.location.href.split( '#' )[ 0 ] ) &&
			( ! storedBlock || storedBlock === form.dataset.blockId )
		) {
			for ( const key of [
				'rrze_appt_slot',
				'rrze_appt_page',
				'rrze_appt_block',
			] ) {
				sessionStorage.removeItem( key );
			}
			void navigate( storedIndex, 'restore', storedSlot );
		} else if (
			schedule.paged &&
			! availableDates.length &&
			months.length > 1
		) {
			// A cached first month can expire before the visitor opens the page.
			void navigate( 1, 'initial' );
		}
	}

	document
		.querySelectorAll< HTMLFormElement >( 'form.rrze-appointment' )
		.forEach( ( form, index ) => {
			initializeAppointmentForm( form, `rrze-appt-${ index + 1 }` );
		} );
}
