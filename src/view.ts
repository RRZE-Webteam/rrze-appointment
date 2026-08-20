import type {
	Booker,
	BookerAjaxResponse,
	BookerResponse,
	BookingResponse,
	FrontendDateMap,
	FrontendSlot,
	ParsedSlotValue,
} from './types';
import { isBookingClosed, isBookingNotOpen } from './booking-window';
import {
	formatDateDisplay,
	formatDateLongDisplay,
	getWeekdayMonthGridCells,
} from './utils';

( function () {
	function run(): void {
		const i18n = window.rrze_appointment?.i18n || {};
		const requestedLocale =
			window.rrze_appointment?.locale ||
			document.documentElement.lang ||
			'de-DE';
		let frontendLocale = requestedLocale;
		try {
			new Intl.DateTimeFormat( frontendLocale ).format();
		} catch ( error ) {
			frontendLocale = 'de-DE';
		}

		const weekdays = Array.from( { length: 7 }, ( unused, index ) => {
			const date = new Date( 2024, 0, index + 1 );
			return {
				short: date.toLocaleDateString( frontendLocale, {
					weekday: 'short',
				} ),
				long: date.toLocaleDateString( frontendLocale, {
					weekday: 'long',
				} ),
			};
		} );

		function formatMonthTitle( dateObj: Date ): string {
			return dateObj.toLocaleDateString( frontendLocale, {
				month: 'long',
				year: 'numeric',
			} );
		}

		function toDateString(
			year: number,
			monthIndex: number,
			day: number
		): string {
			return `${ year }-${ String( monthIndex + 1 ).padStart(
				2,
				'0'
			) }-${ String( day ).padStart( 2, '0' ) }`;
		}

		function parseSlotValue( value: unknown ): ParsedSlotValue {
			const slotString = String( value || '' ).trim();
			const match = slotString.match(
				/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2})(?:-(\d{1,2}:\d{2}))?/
			);
			const date = match ? match[ 1 ] : '';
			const startTime = match ? match[ 2 ] : '';
			const endTime = match ? match[ 3 ] || '' : '';

			return {
				date,
				time: startTime,
				endTime,
				value: slotString,
			};
		}

		function formatSlotLabelFromValue( value: unknown ): string {
			const parsed = parseSlotValue( value );
			if ( ! parsed.time ) {
				return '';
			}
			if ( parsed.endTime ) {
				return `${ parsed.time } - ${ parsed.endTime }`;
			}
			return parsed.time;
		}

		function buildDateMap( inputs: HTMLInputElement[] ): FrontendDateMap {
			const map: FrontendDateMap = new Map();

			inputs.forEach( ( input ) => {
				const value = input.value || '';
				const parsed = parseSlotValue( value );
				if ( ! parsed.date ) {
					return;
				}

				const fallbackTimeLabel = formatSlotLabelFromValue( value );
				const label =
					input.dataset.label?.trim() ||
					input.closest( 'button' )?.textContent?.trim() ||
					input
						.closest( 'label' )
						?.querySelector( 'span' )
						?.textContent?.trim() ||
					fallbackTimeLabel ||
					value;

				const dateSlots = map.get( parsed.date ) || [];
				dateSlots.push( {
					value,
					label,
					time: parsed.time,
				} );
				map.set( parsed.date, dateSlots );
			} );

			return map;
		}

		function initAppointmentForm(
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

			const dateMap = buildDateMap( slotInputs );
			let availableDates: string[] = [];
			let dateSet = new Set< string >();

			let activeDate = '';
			let selectedSlotValue = '';

			const bookedSlots = new Set< string >(
				window.rrze_appointment?.bookedSlots || []
			);
			const bookingCutoff = parseInt(
				form.dataset.bookingCutoff || '0',
				10
			);
			const bookingMaxAdvance = parseInt(
				form.dataset.bookingMaxAdvance || '0',
				10
			);
			const disableSso = form.dataset.disableSso === '1';
			const hideWeekends = form.dataset.hideWeekends === '1';

			function parseSlotStart( slotValue: string ): Date | null {
				const parsed = parseSlotValue( slotValue );
				if ( ! parsed.date || ! parsed.time ) {
					return null;
				}
				const slotStart = new Date(
					`${ parsed.date }T${ parsed.time }:00`
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

			function isSlotCutoff( slotValue: string ): boolean {
				const slotStart = parseSlotStart( slotValue );
				if ( ! slotStart ) {
					return false;
				}
				return isBookingClosed( slotStart, new Date(), bookingCutoff );
			}

			function isSlotTooFarInAdvance( slotValue: string ): boolean {
				const slotStart = parseSlotStart( slotValue );
				if ( ! slotStart ) {
					return false;
				}
				return isBookingNotOpen(
					slotStart,
					new Date(),
					bookingMaxAdvance
				);
			}

			function isSlotUnavailable( slotValue: string ): boolean {
				return (
					bookedSlots.has( slotValue ) ||
					isSlotInPast( slotValue ) ||
					isSlotCutoff( slotValue )
				);
			}

			function getBookingAdvanceMessage(): string {
				const days = Math.max(
					1,
					Math.ceil( bookingMaxAdvance / ( 24 * 60 ) )
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
				const hasAvailableSlot = Array.from( dateMap.values() ).some(
					( slots ) =>
						slots.some(
							( slot ) => ! isSlotUnavailable( slot.value )
						)
				);
				if ( hasAvailableSlot ) {
					clearAvailabilityMessage();
					return;
				}
				showAvailabilityMessage(
					i18n.noSlotsAvailable || 'No time slots available.'
				);
			}

			dateMap.forEach( ( slots, date ) => {
				const filtered = slots.filter(
					( slot ) => ! isSlotUnavailable( slot.value )
				);
				if ( filtered.length > 0 ) {
					dateMap.set( date, filtered );
				} else {
					dateMap.delete( date );
				}
			} );

			availableDates = Array.from( dateMap.keys() ).sort();
			if ( availableDates.length === 0 ) {
				calendarElement.innerHTML = '';
				daySlotsContainerElement.classList.add( 'is-hidden' );
				showAvailabilityMessage(
					i18n.noSlotsAvailable || 'No time slots available.'
				);
				return;
			}
			clearAvailabilityMessage();
			dateSet = new Set( availableDates );
			const firstDate = new Date( `${ availableDates[ 0 ] }T00:00:00` );
			activeDate = availableDates[ 0 ];
			let currentYear = firstDate.getFullYear();
			let currentMonth = firstDate.getMonth();

			function markHiddenInput( value: string ): void {
				slotInputs.forEach( ( input ) => {
					input.checked = input.value === value;
				} );
			}

			function openOverlay(
				value: string,
				booker: Booker = {},
				triggerButton: HTMLButtonElement | null = null
			): void {
				const existingDialog = document.querySelector< HTMLElement >(
					'.rrze-appointment__overlay-box[role="dialog"]'
				);
				if ( existingDialog ) {
					existingDialog.focus();
					return;
				}
				if ( isSlotUnavailable( value ) ) {
					return;
				}
				const parsed = parseSlotValue( value );
				if ( ! parsed.date || ! parsed.time ) {
					return;
				}
				const isOpeningNotification = isSlotTooFarInAdvance( value );

				selectedSlotValue = value;
				markHiddenInput( value );
				form.querySelectorAll(
					'.rrze-appointment__slot-button.is-active'
				).forEach( ( activeButton ) => {
					activeButton.classList.remove( 'is-active' );
				} );
				triggerButton?.classList.add( 'is-active' );

				const titleId = `${ instanceId }-dialog-title`;
				const introId = `${ instanceId }-dialog-intro`;
				const appointmentId = `${ instanceId }-dialog-appointment`;
				const statusId = `${ instanceId }-dialog-status`;
				const overlay = document.createElement( 'div' );
				overlay.className = 'rrze-appointment__overlay';

				const box = document.createElement( 'div' );
				box.className = 'rrze-appointment__overlay-box';
				box.setAttribute( 'role', 'dialog' );
				box.setAttribute( 'aria-modal', 'true' );
				box.tabIndex = -1;
				box.setAttribute( 'aria-labelledby', titleId );
				box.setAttribute(
					'aria-describedby',
					`${ introId } ${ appointmentId }`
				);

				const header = document.createElement( 'div' );
				header.className = 'rrze-appointment__overlay-header';
				const heading = document.createElement( 'h2' );
				heading.className = 'rrze-appointment__overlay-title';
				heading.id = titleId;
				heading.textContent = isOpeningNotification
					? i18n.notifyDialogTitle || 'Notify me when booking opens'
					: i18n.dialogTitle || 'Request appointment';
				const closeBtn = document.createElement( 'button' );
				closeBtn.type = 'button';
				closeBtn.className = 'rrze-appointment__overlay-close';
				closeBtn.setAttribute(
					'aria-label',
					i18n.closeDialog || 'Close dialog'
				);
				closeBtn.textContent = '×';
				header.appendChild( heading );
				header.appendChild( closeBtn );

				const intro = document.createElement( 'p' );
				intro.className = 'rrze-appointment__overlay-intro';
				intro.id = introId;
				intro.textContent = isOpeningNotification
					? i18n.notifyDialogIntro ||
					  'Enter your details and we will email you as soon as this appointment opens for booking.'
					: i18n.dialogIntro ||
					  'Enter your details to request this appointment. You will receive an email to confirm it.';

				const appointment = document.createElement( 'div' );
				appointment.className = 'rrze-appointment__overlay-appointment';
				appointment.id = appointmentId;
				const appointmentLabel = document.createElement( 'span' );
				appointmentLabel.className =
					'rrze-appointment__overlay-appointment-label';
				appointmentLabel.textContent =
					i18n.selectedAppointment || 'Selected appointment';
				const appointmentDate = document.createElement( 'strong' );
				appointmentDate.className =
					'rrze-appointment__overlay-appointment-date';
				appointmentDate.textContent = formatDateDisplay(
					parsed.date,
					frontendLocale
				);
				const appointmentTime = document.createElement( 'span' );
				appointmentTime.className =
					'rrze-appointment__overlay-appointment-time';
				appointmentTime.textContent = parsed.endTime
					? `${ parsed.time }–${ parsed.endTime }`
					: parsed.time;
				appointment.appendChild( appointmentLabel );
				appointment.appendChild( appointmentDate );
				appointment.appendChild( appointmentTime );
				const previousBodyOverflow = document.body.style.overflow;
				const inertedSiblings: HTMLElement[] = [];

				function isolateDialog(): void {
					Array.from( document.body.children ).forEach( ( child ) => {
						if (
							child !== overlay &&
							child instanceof HTMLElement &&
							! child.hasAttribute( 'inert' )
						) {
							child.setAttribute( 'inert', '' );
							inertedSiblings.push( child );
						}
					} );
				}

				function restorePage(): void {
					inertedSiblings.forEach( ( sibling ) => {
						sibling.removeAttribute( 'inert' );
					} );
				}

				// Focus-Trap: alle fokussierbaren Elemente im Dialog
				function getFocusable(): HTMLElement[] {
					return Array.from(
						box.querySelectorAll< HTMLElement >(
							'input, textarea, select, button:not([disabled]), [tabindex]:not([tabindex="-1"])'
						)
					);
				}

				function trapFocus( e: KeyboardEvent ): void {
					if ( e.key !== 'Tab' ) {
						return;
					}
					const focusable = getFocusable();
					if ( ! focusable.length ) {
						return;
					}
					const first = focusable[ 0 ];
					const last = focusable[ focusable.length - 1 ];
					if ( e.shiftKey ) {
						if ( box.ownerDocument.activeElement === first ) {
							e.preventDefault();
							last.focus();
						}
					} else if ( box.ownerDocument.activeElement === last ) {
						e.preventDefault();
						first.focus();
					}
				}

				const dialogForm = document.createElement( 'form' );
				dialogForm.className = 'rrze-appointment__overlay-form';
				dialogForm.noValidate = true;
				const fields = document.createElement( 'div' );
				fields.className = 'rrze-appointment__overlay-fields';

				const nameLabel = document.createElement( 'label' );
				nameLabel.className = 'rrze-appointment__overlay-label';
				const nameLabelText = document.createElement( 'span' );
				nameLabelText.textContent = `${ i18n.yourName || 'Name' } (${
					i18n.required || 'required'
				})`;
				const nameInput = document.createElement( 'input' );
				nameInput.type = 'text';
				nameInput.id = `${ instanceId }-name`;
				nameInput.className = 'rrze-appointment__overlay-name';
				nameInput.autocomplete = 'name';
				nameInput.placeholder =
					i18n.namePlaceholder || 'First and last name';
				nameInput.value = booker.bookerName || '';
				nameInput.readOnly = !! booker.bookerName;
				nameInput.required = true;
				nameLabel.appendChild( nameLabelText );
				nameLabel.appendChild( nameInput );

				const emailLabel = document.createElement( 'label' );
				emailLabel.className = 'rrze-appointment__overlay-label';
				const emailLabelText = document.createElement( 'span' );
				emailLabelText.textContent = `${
					i18n.yourEmail || 'Email address'
				} (${ i18n.required || 'required' })`;
				const emailInput = document.createElement( 'input' );
				emailInput.type = 'email';
				emailInput.id = `${ instanceId }-email`;
				emailInput.className = 'rrze-appointment__overlay-email';
				emailInput.autocomplete = 'email';
				emailInput.placeholder = 'name@example.com';
				emailInput.value = booker.bookerEmail || '';
				emailInput.readOnly = !! booker.bookerEmail;
				emailInput.required = true;
				emailLabel.appendChild( emailLabelText );
				emailLabel.appendChild( emailInput );

				const waitlistLabel = document.createElement( 'label' );
				waitlistLabel.className =
					'rrze-appointment__overlay-waitlist rrze-appointment__overlay-label--wide';
				const waitlistCheckbox = document.createElement( 'input' );
				waitlistCheckbox.type = 'checkbox';
				waitlistCheckbox.className =
					'rrze-appointment__overlay-waitlist-checkbox';
				waitlistLabel.appendChild( waitlistCheckbox );
				waitlistLabel.appendChild(
					document.createTextNode(
						' ' +
							( window.rrze_appointment?.i18n?.waitlist ||
								'Notify me if an earlier appointment becomes available.' )
					)
				);

				const status = document.createElement( 'p' );
				status.id = statusId;
				status.className = 'rrze-appointment__overlay-status is-hidden';
				status.setAttribute( 'role', 'status' );
				status.setAttribute( 'aria-live', 'polite' );
				status.setAttribute( 'aria-atomic', 'true' );

				const actions = document.createElement( 'div' );
				actions.className = 'rrze-appointment__overlay-actions';

				const confirmBtn = document.createElement( 'button' );
				confirmBtn.type = 'submit';
				confirmBtn.className = 'rrze-appointment__overlay-confirm';
				confirmBtn.textContent = isOpeningNotification
					? i18n.notifyButton || 'Notify me'
					: i18n.book || 'Request appointment';

				const cancelBtn = document.createElement( 'button' );
				cancelBtn.type = 'button';
				cancelBtn.className = 'rrze-appointment__overlay-cancel';
				cancelBtn.textContent = i18n.cancel || 'Cancel';

				let isClosed = false;
				let isSubmitting = false;
				function closeOverlay(): void {
					if ( isClosed || isSubmitting ) {
						return;
					}
					isClosed = true;
					document.removeEventListener( 'keydown', onKey );
					document.body.style.overflow = previousBodyOverflow;
					restorePage();
					overlay.remove();
					if (
						triggerButton?.isConnected &&
						! triggerButton.disabled
					) {
						triggerButton.focus();
						return;
					}
					const slotReplacement = Array.from(
						form.querySelectorAll< HTMLButtonElement >(
							'.rrze-appointment__slot-button'
						)
					).find(
						( button ) =>
							button.dataset.slotValue === value &&
							! button.disabled
					);
					const fallback =
						slotReplacement ||
						form.querySelector< HTMLElement >(
							'.rrze-appointment__day-slots:not(.is-hidden) .rrze-appointment__day-slots-title'
						) ||
						form.querySelector< HTMLElement >(
							'.rrze-appointment__calendar-month-title'
						);
					fallback?.focus();
				}

				function onKey( e: KeyboardEvent ): void {
					if ( e.key === 'Escape' ) {
						closeOverlay();
					}
				}

				function clearStatus(): void {
					status.textContent = '';
					status.className =
						'rrze-appointment__overlay-status is-hidden';
					status.setAttribute( 'role', 'status' );
					status.setAttribute( 'aria-live', 'polite' );
				}

				function showStatus(
					message: string,
					type: 'error' | 'loading' | 'success'
				): void {
					status.textContent = message;
					status.className = `rrze-appointment__overlay-status is-${ type }`;
					status.setAttribute(
						'role',
						type === 'error' ? 'alert' : 'status'
					);
					status.setAttribute(
						'aria-live',
						type === 'error' ? 'assertive' : 'polite'
					);
				}

				function showFieldError(
					input:
						| HTMLInputElement
						| HTMLTextAreaElement
						| HTMLSelectElement,
					message: string
				): void {
					input.setAttribute( 'aria-invalid', 'true' );
					input.setAttribute( 'aria-describedby', statusId );
					input.setAttribute( 'aria-errormessage', statusId );
					showStatus( message, 'error' );
					input.focus();
				}

				[ nameInput, emailInput ].forEach( ( input ) => {
					input.addEventListener( 'input', () => {
						input.removeAttribute( 'aria-invalid' );
						input.removeAttribute( 'aria-describedby' );
						input.removeAttribute( 'aria-errormessage' );
						if ( status.classList.contains( 'is-error' ) ) {
							clearStatus();
						}
					} );
					input.addEventListener( 'change', () => {
						input.removeAttribute( 'aria-invalid' );
						input.removeAttribute( 'aria-describedby' );
						input.removeAttribute( 'aria-errormessage' );
						if ( status.classList.contains( 'is-error' ) ) {
							clearStatus();
						}
					} );
				} );

				closeBtn.addEventListener( 'click', closeOverlay );
				cancelBtn.addEventListener( 'click', closeOverlay );
				overlay.addEventListener( 'click', ( e ) => {
					if ( e.target === overlay ) {
						closeOverlay();
					}
				} );
				overlay.addEventListener( 'keydown', trapFocus );
				document.addEventListener( 'keydown', onKey );

				dialogForm.addEventListener( 'submit', ( event ) => {
					event.preventDefault();
					if ( isSubmitting ) {
						return;
					}
					clearStatus();
					const nameValue = nameInput.value.trim();

					if ( ! nameValue ) {
						showFieldError(
							nameInput,
							i18n.nameRequired || 'Enter your name.'
						);
						return;
					}

					const emailValue = emailInput.value.trim();
					const emailIsValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
						emailValue
					);
					if ( ! emailValue || ! emailIsValid ) {
						showFieldError(
							emailInput,
							i18n.emailRequired || 'Enter a valid email address.'
						);
						return;
					}

					confirmBtn.disabled = true;
					cancelBtn.disabled = true;
					closeBtn.disabled = true;
					isSubmitting = true;
					dialogForm.setAttribute( 'aria-busy', 'true' );
					showStatus(
						isOpeningNotification
							? i18n.notifySending || 'Saving notification…'
							: i18n.booking || 'Sending request…',
						'loading'
					);

					const data = new FormData();
					data.append(
						'action',
						isOpeningNotification
							? 'rrze_appointment_notify_opening'
							: 'rrze_appointment_book'
					);
					data.append(
						'nonce',
						window.rrze_appointment?.nonce || ''
					);
					data.append( 'slot', value );
					data.append( 'post_id', form.dataset.postId || '0' );
					data.append( 'block_id', form.dataset.blockId || '' );
					data.append( 'booker_email', emailValue );
					data.append( 'booker_name', nameValue );
					data.append(
						'booker_waitlist',
						waitlistCheckbox.checked ? '1' : '0'
					);

					fetch(
						window.rrze_appointment?.ajaxUrl ||
							'/wp-admin/admin-ajax.php',
						{
							method: 'POST',
							body: data,
						}
					)
						.then( ( r ) => r.json() as Promise< BookingResponse > )
						.then( ( res ) => {
							if ( res.success ) {
								const responseData =
									typeof res.data === 'object'
										? res.data
										: null;
								if (
									isOpeningNotification &&
									responseData?.redirectUrl
								) {
									window.location.assign(
										responseData.redirectUrl
									);
									return;
								}
								if ( ! isOpeningNotification ) {
									bookedSlots.add( value );
								}
								isSubmitting = false;
								dialogForm.removeAttribute( 'aria-busy' );
								showStatus(
									isOpeningNotification
										? i18n.notifySuccess ||
												'We will email you when this appointment opens for booking.'
										: i18n.booked ||
												'Check your inbox to confirm the appointment. We sent a confirmation link to your email address.',
									'success'
								);
								fields.hidden = true;
								intro.hidden = true;
								heading.textContent = isOpeningNotification
									? i18n.notifySuccessTitle ||
									  'Notification registered'
									: i18n.successTitle || 'Check your inbox';
								heading.tabIndex = -1;
								box.classList.add( 'is-success' );
								confirmBtn.remove();
								cancelBtn.textContent = i18n.close || 'Close';
								cancelBtn.disabled = false;
								closeBtn.disabled = false;
								if ( ! isOpeningNotification ) {
									renderCalendar();
									renderDaySlots( activeDate );
									refreshAvailabilityMessage();
								}
								heading.focus();
							} else {
								isSubmitting = false;
								dialogForm.removeAttribute( 'aria-busy' );
								const responseMessage =
									typeof res.data === 'string'
										? res.data
										: res.data?.message;
								showStatus(
									responseMessage ||
										i18n.bookingError ||
										"We couldn't request this appointment. Please try again.",
									'error'
								);
								confirmBtn.disabled = false;
								cancelBtn.disabled = false;
								closeBtn.disabled = false;
							}
						} )
						.catch( () => {
							isSubmitting = false;
							dialogForm.removeAttribute( 'aria-busy' );
							showStatus(
								i18n.networkError ||
									'Connection problem. Check your internet connection and try again.',
								'error'
							);
							confirmBtn.disabled = false;
							cancelBtn.disabled = false;
							closeBtn.disabled = false;
						} );
				} );

				actions.appendChild( cancelBtn );
				actions.appendChild( confirmBtn );
				fields.appendChild( nameLabel );
				fields.appendChild( emailLabel );
				if ( ! isOpeningNotification ) {
					fields.appendChild( waitlistLabel );
				}
				dialogForm.appendChild( fields );
				dialogForm.appendChild( status );
				dialogForm.appendChild( actions );
				box.appendChild( header );
				box.appendChild( intro );
				box.appendChild( appointment );
				box.appendChild( dialogForm );
				overlay.appendChild( box );
				document.body.appendChild( overlay );
				document.body.style.overflow = 'hidden';
				if ( ! nameInput.value ) {
					nameInput.focus();
				} else if ( ! emailInput.value ) {
					emailInput.focus();
				} else {
					confirmBtn.focus();
				}
				isolateDialog();
			}

			function createSlotButton( slot: FrontendSlot ): HTMLButtonElement {
				const isBooked = isSlotUnavailable( slot.value );
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

				if ( isBooked ) {
					button.classList.add( 'is-booked' );
					button.disabled = true;
					return button;
				}
				const isNotOpen = isSlotTooFarInAdvance( slot.value );
				if ( isNotOpen ) {
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
					if ( disableSso ) {
						openOverlay( slot.value, {}, button );
						return;
					}

					button.disabled = true;
					button.setAttribute( 'aria-busy', 'true' );
					form.setAttribute( 'aria-busy', 'true' );
					setInteractionStatus(
						i18n.bookingDetailsLoading || 'Loading booking details…'
					);

					fetch(
						window.rrze_appointment?.restUrl ||
							'/wp-json/rrze/v2/appointment/booker',
						{
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
							},
							body: JSON.stringify( {
								returnTo:
									window.location.href.split( '#' )[ 0 ],
							} ),
						}
					)
						.then( async ( r ) => {
							const text = await r.text();

							const trimmed = text.trim();

							if (
								trimmed.startsWith( '<!DOCTYPE' ) ||
								trimmed.startsWith( '<html' )
							) {
								document.open();
								document.write( text );
								document.close();
								return null;
							}

							try {
								return JSON.parse( text ) as BookerResponse;
							} catch ( e ) {
								throw new Error(
									'Invalid JSON response: ' + text
								);
							}
						} )
						.then( ( res ) => {
							button.disabled = false;
							button.removeAttribute( 'aria-busy' );
							form.removeAttribute( 'aria-busy' );
							setInteractionStatus();

							if ( ! res ) {
								return;
							}

							if ( res.needsLogin ) {
								const loginUrl = ( res.loginUrl || '' ).trim();
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

							const booker = res.data || {};

							openOverlay( slot.value, booker, button );
						} )
						.catch( () => {
							button.disabled = false;
							button.removeAttribute( 'aria-busy' );
							form.removeAttribute( 'aria-busy' );
							setInteractionStatus();

							openOverlay( slot.value, {}, button );
						} );
				} );
				return button;
			}

			function renderDaySlots( date: string, focusTitle = false ): void {
				const slots = ( dateMap.get( date ) || [] ).filter(
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
				const title = daySlotsContainerElement.querySelector(
					'.rrze-appointment__day-slots-title'
				);
				if ( title ) {
					title.textContent = (
						hasBookableSlots
							? i18n.availableOn || 'Available appointments on %s'
							: i18n.appointmentsOn || 'Appointments on %s'
					).replace(
						'%s',
						formatDateDisplay( date, frontendLocale )
					);
					( title as HTMLElement ).tabIndex = -1;
				}

				const existingNotice = daySlotsContainerElement.querySelector(
					'.rrze-appointment__booking-window-notice'
				);
				existingNotice?.remove();
				if ( hasNotOpenSlots ) {
					const notice = document.createElement( 'p' );
					notice.className =
						'rrze-appointment__booking-window-notice';
					notice.textContent = getBookingAdvanceMessage();
					daySlotsListElement.before( notice );
				}

				slots.forEach( ( slot ) => {
					daySlotsListElement.appendChild( createSlotButton( slot ) );
				} );

				if ( focusTitle && title instanceof HTMLElement ) {
					title.focus();
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
				const previousMonth = new Date(
					currentYear,
					currentMonth - 1,
					1
				);
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

				const prevBtn = document.createElement( 'button' );
				prevBtn.type = 'button';
				prevBtn.textContent = '‹';
				prevBtn.className = 'rrze-appointment__calendar-nav';
				prevBtn.dataset.direction = 'previous';
				prevBtn.disabled = currentMonthNumber <= firstMonthNumber;
				prevBtn.setAttribute(
					'aria-label',
					`${
						i18n.previousMonth || 'Previous month'
					}: ${ formatMonthTitle( previousMonth ) }`
				);
				prevBtn.addEventListener( 'click', () => {
					currentYear = previousMonth.getFullYear();
					currentMonth = previousMonth.getMonth();
					renderCalendar( 'previous' );
				} );

				const nextBtn = document.createElement( 'button' );
				nextBtn.type = 'button';
				nextBtn.textContent = '›';
				nextBtn.className = 'rrze-appointment__calendar-nav';
				nextBtn.dataset.direction = 'next';
				nextBtn.disabled = currentMonthNumber >= lastMonthNumber;
				nextBtn.setAttribute(
					'aria-label',
					`${ i18n.nextMonth || 'Next month' }: ${ formatMonthTitle(
						nextMonth
					) }`
				);
				nextBtn.addEventListener( 'click', () => {
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
				titleText.textContent = formatMonthTitle( monthDate );

				titleRow.appendChild( prevBtn );
				titleRow.appendChild( titleText );
				titleRow.appendChild( nextBtn );
				monthWrapper.appendChild( titleRow );

				const grid = document.createElement( 'div' );
				grid.className = [
					'rrze-appointment__calendar-grid',
					hideWeekends ? 'is-hide-weekends' : '',
				]
					.filter( Boolean )
					.join( ' ' );

				const today = new Date();
				const todayStr = toDateString(
					today.getFullYear(),
					today.getMonth(),
					today.getDate()
				);

				function appendDayButton(
					day: number,
					dateString: string
				): void {
					const dayOfWeek = new Date(
						year,
						monthIndex,
						day
					).getDay();
					const isWeekend = dayOfWeek === 0 || dayOfWeek === 6;
					const isPast = dateString < todayStr;
					const isToday = dateString === todayStr;
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

					if ( dateSet.has( dateString ) ) {
						const dateSlots = dateMap.get( dateString ) || [];
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

					getWeekdayMonthGridCells( year, monthIndex ).forEach(
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

					for ( let e = 0; e < firstWeekdayIndex; e += 1 ) {
						const empty = document.createElement( 'div' );
						empty.className = 'rrze-appointment__calendar-empty';
						grid.appendChild( empty );
					}

					for ( let day = 1; day <= daysInMonth; day += 1 ) {
						const dateString = toDateString(
							year,
							monthIndex,
							day
						);
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

			// Nach SSO-Login: Slot aus sessionStorage lesen und Overlay automatisch öffnen
			const autoSlot = sessionStorage.getItem( 'rrze_appt_slot' );
			const autoPage = sessionStorage.getItem( 'rrze_appt_page' );
			const onCorrectPage =
				! autoPage ||
				autoPage === window.location.href.split( '#' )[ 0 ];
			if ( autoSlot && onCorrectPage && ! disableSso ) {
				sessionStorage.removeItem( 'rrze_appt_slot' );
				sessionStorage.removeItem( 'rrze_appt_page' );

				const autoSlotDate = parseSlotValue( autoSlot ).date;
				if ( autoSlotDate && dateMap.has( autoSlotDate ) ) {
					const autoDate = new Date( `${ autoSlotDate }T00:00:00` );
					activeDate = autoSlotDate;
					currentYear = autoDate.getFullYear();
					currentMonth = autoDate.getMonth();
					renderCalendar();
					renderDaySlots( activeDate );
				}

				form.setAttribute( 'aria-busy', 'true' );
				setInteractionStatus(
					i18n.bookingDetailsLoading || 'Loading booking details…'
				);
				// Booker-Daten holen und Overlay öffnen
				const data = new FormData();
				data.append( 'action', 'rrze_appointment_get_booker' );
				fetch(
					window.rrze_appointment?.ajaxUrl ||
						'/wp-admin/admin-ajax.php',
					{
						method: 'POST',
						body: data,
					}
				)
					.then( ( r ) => r.json() as Promise< BookerAjaxResponse > )
					.then( ( res ) => {
						form.removeAttribute( 'aria-busy' );
						setInteractionStatus();
						const booker = res.success ? res.data || {} : {};
						openOverlay( autoSlot, booker );
					} )
					.catch( () => {
						form.removeAttribute( 'aria-busy' );
						setInteractionStatus();
						openOverlay( autoSlot, {} );
					} );
			}
		}

		document
			.querySelectorAll< HTMLFormElement >( 'form.rrze-appointment' )
			.forEach( ( form, index ) => {
				initAppointmentForm( form, `rrze-appt-${ index + 1 }` );
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
