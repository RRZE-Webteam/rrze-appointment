import type {
	AppointmentQuestion,
	Booker,
	BookerAjaxResponse,
	BookerResponse,
	BookingResponse,
	FrontendDateMap,
	FrontendSlot,
	ParsedSlotValue,
} from './types';
import { formatDateDisplay, getWeekdayMonthGridCells } from './utils';

( function () {
	function run(): void {
		const WEEKDAYS = [ 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So' ];

		function formatMonthTitle( dateObj: Date ): string {
			return dateObj.toLocaleDateString( 'de-DE', {
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

		function parseQuestions(
			value: string | undefined
		): AppointmentQuestion[] {
			if ( ! value ) {
				return [];
			}

			try {
				const parsed: unknown = JSON.parse( value );
				if ( ! Array.isArray( parsed ) ) {
					return [];
				}

				const ids = new Set< string >();
				return parsed.flatMap( ( rawQuestion ) => {
					if ( ! rawQuestion || typeof rawQuestion !== 'object' ) {
						return [];
					}
					const source = rawQuestion as Record< string, unknown >;
					const id = String( source.id || '' ).trim();
					const label = String( source.label || '' ).trim();
					const type = source.type === 'select' ? 'select' : 'text';
					const options = Array.isArray( source.options )
						? source.options
								.map( ( option ) => String( option ).trim() )
								.filter( Boolean )
						: [];
					if (
						! id ||
						! label ||
						ids.has( id ) ||
						( type === 'select' && options.length === 0 )
					) {
						return [];
					}
					ids.add( id );

					return [
						{
							id,
							label,
							type,
							required: !! source.required,
							options,
						},
					];
				} );
			} catch {
				return [];
			}
		}

		function initAppointmentForm( form: HTMLFormElement ): void {
			const calendar = form.querySelector< HTMLElement >(
				'.rrze-appointment__calendar'
			);
			const daySlotsFieldset = form.querySelector< HTMLElement >(
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
				! daySlotsFieldset ||
				! daySlotsList ||
				! slotData
			) {
				return;
			}

			const calendarElement = calendar;
			const daySlotsFieldsetElement = daySlotsFieldset;
			const daySlotsListElement = daySlotsList;
			const slotDataElement = slotData;

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
			const requireMessage = form.dataset.requireMessage === '1';
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
				if ( ! bookingCutoff ) {
					return false;
				}
				const slotStart = parseSlotStart( slotValue );
				if ( ! slotStart ) {
					return false;
				}
				return (
					slotStart.getTime() - Date.now() < bookingCutoff * 60 * 1000
				);
			}

			function isSlotUnavailable( slotValue: string ): boolean {
				return (
					bookedSlots.has( slotValue ) ||
					isSlotInPast( slotValue ) ||
					isSlotCutoff( slotValue )
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
				daySlotsFieldsetElement.classList.add( 'is-hidden' );
				return;
			}
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
				if ( isSlotUnavailable( value ) ) {
					return;
				}
				const parsed = parseSlotValue( value );
				if ( ! parsed.date || ! parsed.time ) {
					return;
				}

				selectedSlotValue = value;
				markHiddenInput( value );

				const i18n = window.rrze_appointment?.i18n || {};
				const questions = parseQuestions( form.dataset.questions );
				const overlay = document.createElement( 'div' );
				overlay.className = 'rrze-appointment__overlay';

				const box = document.createElement( 'div' );
				box.className = 'rrze-appointment__overlay-box';
				box.setAttribute( 'role', 'dialog' );
				box.setAttribute( 'aria-modal', 'true' );
				box.setAttribute(
					'aria-labelledby',
					'rrze-appt-overlay-title'
				);
				box.setAttribute(
					'aria-describedby',
					'rrze-appt-overlay-intro'
				);

				const header = document.createElement( 'div' );
				header.className = 'rrze-appointment__overlay-header';
				const heading = document.createElement( 'h2' );
				heading.className = 'rrze-appointment__overlay-title';
				heading.id = 'rrze-appt-overlay-title';
				heading.textContent = i18n.dialogTitle || 'Request appointment';
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
				intro.id = 'rrze-appt-overlay-intro';
				intro.textContent =
					i18n.dialogIntro ||
					'Enter your details to request this appointment. You will receive an email to confirm it.';

				const appointment = document.createElement( 'div' );
				appointment.className = 'rrze-appointment__overlay-appointment';
				const appointmentLabel = document.createElement( 'span' );
				appointmentLabel.className =
					'rrze-appointment__overlay-appointment-label';
				appointmentLabel.textContent =
					i18n.selectedAppointment || 'Selected appointment';
				const appointmentDate = document.createElement( 'strong' );
				appointmentDate.className =
					'rrze-appointment__overlay-appointment-date';
				appointmentDate.textContent = formatDateDisplay( parsed.date );
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
				nameLabelText.textContent = i18n.yourName || 'Name';
				const nameInput = document.createElement( 'input' );
				nameInput.type = 'text';
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
				emailLabelText.textContent = i18n.yourEmail || 'Email address';
				const emailInput = document.createElement( 'input' );
				emailInput.type = 'email';
				emailInput.className = 'rrze-appointment__overlay-email';
				emailInput.autocomplete = 'email';
				emailInput.placeholder = 'name@example.com';
				emailInput.value = booker.bookerEmail || '';
				emailInput.readOnly = !! booker.bookerEmail;
				emailInput.required = true;
				emailLabel.appendChild( emailLabelText );
				emailLabel.appendChild( emailInput );

				const messageLabel = document.createElement( 'label' );
				messageLabel.className =
					'rrze-appointment__overlay-label rrze-appointment__overlay-label--message';
				const optionalMessageLabel =
					i18n.messageOptional ||
					i18n.message ||
					'Message (optional)';
				const requiredMessageLabel = (
					i18n.message || optionalMessageLabel
				)
					.replace( /\s*\(\s*optional\s*\)\s*:?/i, ':' )
					.replace( /\s{2,}/g, ' ' )
					.trim();
				messageLabel.textContent = requireMessage
					? requiredMessageLabel
					: optionalMessageLabel;
				const messageInput = document.createElement( 'textarea' );
				messageInput.className = 'rrze-appointment__overlay-message';
				messageInput.placeholder =
					i18n.messagePlaceholder ||
					'What would you like to discuss?';
				messageInput.rows = 4;
				messageInput.required = requireMessage;
				messageLabel.appendChild( messageInput );

				type QuestionInput = HTMLTextAreaElement | HTMLSelectElement;
				const questionFields: Array< {
					question: AppointmentQuestion;
					input: QuestionInput;
				} > = [];
				questions.forEach( ( question ) => {
					const questionLabel = document.createElement( 'label' );
					questionLabel.className =
						'rrze-appointment__overlay-label rrze-appointment__overlay-label--wide';
					const questionLabelText = document.createElement( 'span' );
					questionLabelText.textContent = `${ question.label }${
						question.required ? ' *' : ''
					}`;
					questionLabel.appendChild( questionLabelText );

					let questionInput: QuestionInput;
					if ( question.type === 'select' ) {
						const select = document.createElement( 'select' );
						const placeholder = document.createElement( 'option' );
						placeholder.value = '';
						placeholder.textContent =
							i18n.selectOption || 'Select an option';
						select.appendChild( placeholder );
						question.options.forEach( ( option ) => {
							const optionElement =
								document.createElement( 'option' );
							optionElement.value = option;
							optionElement.textContent = option;
							select.appendChild( optionElement );
						} );
						questionInput = select;
					} else {
						const textarea = document.createElement( 'textarea' );
						textarea.rows = 3;
						questionInput = textarea;
					}
					questionInput.className =
						'rrze-appointment__overlay-question';
					questionInput.required = question.required;
					questionInput.dataset.questionId = question.id;
					questionLabel.appendChild( questionInput );
					questionFields.push( { question, input: questionInput } );
				} );

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
				status.className = 'rrze-appointment__overlay-status is-hidden';
				status.setAttribute( 'aria-live', 'polite' );

				const actions = document.createElement( 'div' );
				actions.className = 'rrze-appointment__overlay-actions';

				const confirmBtn = document.createElement( 'button' );
				confirmBtn.type = 'submit';
				confirmBtn.className = 'rrze-appointment__overlay-confirm';
				confirmBtn.textContent = i18n.book || 'Request appointment';

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
					overlay.remove();
					if ( triggerButton ) {
						triggerButton.focus();
					}
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
				}

				function showStatus(
					message: string,
					type: 'error' | 'loading' | 'success'
				): void {
					status.textContent = message;
					status.className = `rrze-appointment__overlay-status is-${ type }`;
				}

				function showFieldError(
					input:
						| HTMLInputElement
						| HTMLTextAreaElement
						| HTMLSelectElement,
					message: string
				): void {
					input.setAttribute( 'aria-invalid', 'true' );
					showStatus( message, 'error' );
					input.focus();
				}

				[
					nameInput,
					emailInput,
					messageInput,
					...questionFields.map( ( field ) => field.input ),
				].forEach( ( input ) => {
					input.addEventListener( 'input', () => {
						input.removeAttribute( 'aria-invalid' );
						if ( status.classList.contains( 'is-error' ) ) {
							clearStatus();
						}
					} );
					input.addEventListener( 'change', () => {
						input.removeAttribute( 'aria-invalid' );
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

					const messageValue = messageInput.value.trim();
					if ( requireMessage && ! messageValue ) {
						showFieldError(
							messageInput,
							i18n.messageRequired || 'Enter a message.'
						);
						return;
					}

					const questionAnswers: Record< string, string > = {};
					for ( const { question, input } of questionFields ) {
						const answer = input.value.trim();
						if ( question.required && ! answer ) {
							showFieldError(
								input,
								i18n.questionRequired ||
									'Answer this required question.'
							);
							return;
						}
						questionAnswers[ question.id ] = answer;
					}

					confirmBtn.disabled = true;
					cancelBtn.disabled = true;
					closeBtn.disabled = true;
					isSubmitting = true;
					showStatus( i18n.booking || 'Sending request…', 'loading' );

					const data = new FormData();
					data.append( 'action', 'rrze_appointment_book' );
					data.append(
						'nonce',
						window.rrze_appointment?.nonce || ''
					);
					data.append( 'slot', value );
					data.append( 'post_id', form.dataset.postId || '0' );
					data.append( 'block_id', form.dataset.blockId || '' );
					data.append( 'booker_email', emailValue );
					data.append( 'booker_name', nameValue );
					data.append( 'booker_message', messageValue );
					data.append(
						'question_answers',
						JSON.stringify( questionAnswers )
					);
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
								bookedSlots.add( value );
								isSubmitting = false;
								showStatus(
									i18n.booked ||
										'Check your inbox to confirm the appointment. We sent a confirmation link to your email address.',
									'success'
								);
								fields.hidden = true;
								intro.hidden = true;
								heading.textContent =
									i18n.successTitle || 'Check your inbox';
								heading.tabIndex = -1;
								box.classList.add( 'is-success' );
								confirmBtn.remove();
								cancelBtn.textContent = i18n.close || 'Close';
								cancelBtn.disabled = false;
								closeBtn.disabled = false;
								renderCalendar();
								renderDaySlots( activeDate );
								heading.focus();
							} else {
								isSubmitting = false;
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
				fields.appendChild( messageLabel );
				questionFields.forEach( ( { input } ) => {
					const label = input.closest( 'label' );
					if ( label ) {
						fields.appendChild( label );
					}
				} );
				fields.appendChild( waitlistLabel );
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
					const firstRequiredQuestion = questionFields.find(
						( { question, input } ) =>
							question.required && ! input.value
					);
					if ( firstRequiredQuestion ) {
						firstRequiredQuestion.input.focus();
					} else {
						confirmBtn.focus();
					}
				}
			}

			function createSlotButton( slot: FrontendSlot ): HTMLButtonElement {
				const isBooked = isSlotUnavailable( slot.value );
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'rrze-appointment__slot-button';
				button.textContent = slot.label;
				button.dataset.slotValue = slot.value;

				if ( isBooked ) {
					button.classList.add( 'is-booked' );
					button.disabled = true;
					return button;
				}

				if ( selectedSlotValue && slot.value === selectedSlotValue ) {
					button.classList.add( 'is-active' );
				}

				button.addEventListener( 'click', () => {
					if ( disableSso ) {
						openOverlay( slot.value, {}, button );
						renderDaySlots( activeDate );
						return;
					}

					button.disabled = true;

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
							renderDaySlots( activeDate );
						} )
						.catch( () => {
							button.disabled = false;

							openOverlay( slot.value, {}, button );
							renderDaySlots( activeDate );
						} );
				} );
				return button;
			}

			function renderDaySlots( date: string ): void {
				const slots = dateMap.get( date ) || [];

				daySlotsListElement.innerHTML = '';
				daySlotsListElement.className =
					'rrze-appointment__day-slots-list rrze-appointment__slot-grid';

				if ( slots.length === 0 ) {
					daySlotsFieldsetElement.classList.add( 'is-hidden' );
					return;
				}

				daySlotsFieldsetElement.classList.remove( 'is-hidden' );
				const legend =
					daySlotsFieldsetElement.querySelector( 'legend' ) ||
					daySlotsFieldsetElement.querySelector(
						'.rrze-appointment__day-slots-title'
					);
				if ( legend ) {
					legend.textContent = (
						i18n.availableOn || 'Available appointments on %s'
					).replace( '%s', formatDateDisplay( date ) );
				}

				slots.forEach( ( slot ) => {
					daySlotsListElement.appendChild( createSlotButton( slot ) );
				} );
			}

			function renderCalendar(): void {
				calendarElement.innerHTML = '';

				const monthDate = new Date( currentYear, currentMonth, 1 );
				const year = monthDate.getFullYear();
				const monthIndex = monthDate.getMonth();

				const monthWrapper = document.createElement( 'div' );
				monthWrapper.className = 'rrze-appointment__calendar-month';

				const titleRow = document.createElement( 'div' );
				titleRow.className = 'rrze-appointment__calendar-title';

				const prevBtn = document.createElement( 'button' );
				prevBtn.type = 'button';
				prevBtn.textContent = '‹';
				prevBtn.className = 'rrze-appointment__calendar-nav';
				prevBtn.addEventListener( 'click', () => {
					const prev = new Date( currentYear, currentMonth - 1, 1 );
					currentYear = prev.getFullYear();
					currentMonth = prev.getMonth();
					renderCalendar();
				} );

				const nextBtn = document.createElement( 'button' );
				nextBtn.type = 'button';
				nextBtn.textContent = '›';
				nextBtn.className = 'rrze-appointment__calendar-nav';
				nextBtn.addEventListener( 'click', () => {
					const next = new Date( currentYear, currentMonth + 1, 1 );
					currentYear = next.getFullYear();
					currentMonth = next.getMonth();
					renderCalendar();
				} );

				const titleText = document.createElement( 'span' );
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
					if ( isWeekend || isPast ) {
						button.classList.add( 'is-past' );
					}
					if ( isWeekend ) {
						button.classList.add( 'is-weekend' );
					}
					if ( isToday ) {
						button.classList.add( 'is-today' );
					}
					button.textContent = String( day );

					if ( dateSet.has( dateString ) ) {
						const dateSlots = dateMap.get( dateString ) || [];
						const allBooked =
							dateSlots.length > 0 &&
							dateSlots.every(
								( s ) =>
									bookedSlots.has( s.value ) ||
									isSlotInPast( s.value ) ||
									isSlotCutoff( s.value )
							);

						if ( ! allBooked ) {
							button.classList.add( 'is-available' );
						}
						if ( allBooked ) {
							button.classList.add( 'is-booked' );
						}
						if ( dateString === activeDate ) {
							button.classList.add( 'is-active' );
						}

						if ( ! allBooked ) {
							button.addEventListener( 'click', () => {
								activeDate = dateString;
								renderCalendar();
								renderDaySlots( activeDate );
							} );
						} else {
							button.disabled = true;
						}
					} else {
						button.disabled = true;
					}

					grid.appendChild( button );
				}

				if ( hideWeekends ) {
					WEEKDAYS.slice( 0, 5 ).forEach( ( weekday ) => {
						const cell = document.createElement( 'div' );
						cell.className = 'rrze-appointment__weekday';
						cell.textContent = weekday;
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
					WEEKDAYS.forEach( ( weekday ) => {
						const cell = document.createElement( 'div' );
						cell.className = 'rrze-appointment__weekday';
						cell.textContent = weekday;
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
			}

			const i18n = window.rrze_appointment?.i18n || {};

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

				// Booker-Daten holen und Overlay öffnen
				const data = new FormData();
				data.append( 'action', 'rrze_appointment_get_booker' );
				fetch(
					window.rrze_appointment?.ajaxUrl ||
						'/wp-admin/admin-ajax.php',
					{ method: 'POST', body: data }
				)
					.then( ( r ) => r.json() as Promise< BookerAjaxResponse > )
					.then( ( res ) => {
						const booker = res.success ? res.data || {} : {};
						openOverlay( autoSlot, booker );
					} )
					.catch( () => openOverlay( autoSlot, {} ) );
			}
		}

		document
			.querySelectorAll< HTMLFormElement >( 'form.rrze-appointment' )
			.forEach( ( form ) => {
				initAppointmentForm( form );
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
} )();
