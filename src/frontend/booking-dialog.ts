import type { FrontendI18n } from '../configuration';
import { formatDateDisplay } from '../scheduling/dates';
import { submitAppointment } from './api';
import { parseSlotValue } from './slot-parser';
import type { Booker } from './types';

interface BookingDialogOptions {
	booker?: Booker;
	form: HTMLFormElement;
	frontendLocale: string;
	i18n: FrontendI18n;
	instanceId: string;
	slotValue: string;
	triggerButton?: HTMLButtonElement | null;
	isSlotTooFarInAdvance: ( slotValue: string ) => boolean;
	isSlotUnavailable: ( slotValue: string ) => boolean;
	onBookingSucceeded: ( slotValue: string ) => void;
	onSlotSelected: ( slotValue: string ) => void;
}

export function openBookingDialog( {
	booker = {},
	form,
	frontendLocale,
	i18n,
	instanceId,
	slotValue,
	triggerButton = null,
	isSlotTooFarInAdvance,
	isSlotUnavailable,
	onBookingSucceeded,
	onSlotSelected,
}: BookingDialogOptions ): void {
	const existingDialog = document.querySelector< HTMLElement >(
		'.rrze-appointment__overlay-box[role="dialog"]'
	);
	if ( existingDialog ) {
		existingDialog.focus();
		return;
	}
	if ( isSlotUnavailable( slotValue ) ) {
		return;
	}
	const parsedSlot = parseSlotValue( slotValue );
	if ( ! parsedSlot.date || ! parsedSlot.time ) {
		return;
	}
	const isOpeningNotification = isSlotTooFarInAdvance( slotValue );

	onSlotSelected( slotValue );
	form.querySelectorAll( '.rrze-appointment__slot-button.is-active' ).forEach(
		( activeButton ) => activeButton.classList.remove( 'is-active' )
	);
	triggerButton?.classList.add( 'is-active' );

	const titleId = `${ instanceId }-dialog-title`;
	const introId = `${ instanceId }-dialog-intro`;
	const appointmentId = `${ instanceId }-dialog-appointment`;
	const statusId = `${ instanceId }-dialog-status`;
	const overlayElement = document.createElement( 'div' );
	overlayElement.className = 'rrze-appointment__overlay';

	const dialogElement = document.createElement( 'div' );
	dialogElement.className = 'rrze-appointment__overlay-box';
	dialogElement.setAttribute( 'role', 'dialog' );
	dialogElement.setAttribute( 'aria-modal', 'true' );
	dialogElement.tabIndex = -1;
	dialogElement.setAttribute( 'aria-labelledby', titleId );
	dialogElement.setAttribute(
		'aria-describedby',
		`${ introId } ${ appointmentId }`
	);

	const dialogHeader = document.createElement( 'div' );
	dialogHeader.className = 'rrze-appointment__overlay-header';
	const dialogHeading = document.createElement( 'h2' );
	dialogHeading.className = 'rrze-appointment__overlay-title';
	dialogHeading.id = titleId;
	dialogHeading.textContent = isOpeningNotification
		? i18n.notifyDialogTitle || 'Notify me when booking opens'
		: i18n.dialogTitle || 'Request appointment';
	const closeButton = document.createElement( 'button' );
	closeButton.type = 'button';
	closeButton.className = 'rrze-appointment__overlay-close';
	closeButton.setAttribute(
		'aria-label',
		i18n.closeDialog || 'Close dialog'
	);
	closeButton.textContent = '×';
	dialogHeader.appendChild( dialogHeading );
	dialogHeader.appendChild( closeButton );

	const dialogIntroduction = document.createElement( 'p' );
	dialogIntroduction.className = 'rrze-appointment__overlay-intro';
	dialogIntroduction.id = introId;
	dialogIntroduction.textContent = isOpeningNotification
		? i18n.notifyDialogIntro ||
		  'Enter your details and we will email you as soon as this appointment opens for booking.'
		: i18n.dialogIntro ||
		  'Enter your details to request this appointment. You will receive an email to confirm it.';

	const appointmentSummary = document.createElement( 'div' );
	appointmentSummary.className = 'rrze-appointment__overlay-appointment';
	appointmentSummary.id = appointmentId;
	const appointmentLabel = document.createElement( 'span' );
	appointmentLabel.className = 'rrze-appointment__overlay-appointment-label';
	appointmentLabel.textContent =
		i18n.selectedAppointment || 'Selected appointment';
	const appointmentDate = document.createElement( 'strong' );
	appointmentDate.className = 'rrze-appointment__overlay-appointment-date';
	appointmentDate.textContent = formatDateDisplay(
		parsedSlot.date,
		frontendLocale
	);
	const appointmentTime = document.createElement( 'span' );
	appointmentTime.className = 'rrze-appointment__overlay-appointment-time';
	appointmentTime.textContent = parsedSlot.endTime
		? `${ parsedSlot.time }–${ parsedSlot.endTime }`
		: parsedSlot.time;
	appointmentSummary.appendChild( appointmentLabel );
	appointmentSummary.appendChild( appointmentDate );
	appointmentSummary.appendChild( appointmentTime );

	const previousBodyOverflow = document.body.style.overflow;
	const inertPageElements: HTMLElement[] = [];

	function isolateDialog(): void {
		Array.from( document.body.children ).forEach( ( child ) => {
			if (
				child !== overlayElement &&
				child instanceof HTMLElement &&
				! child.hasAttribute( 'inert' )
			) {
				child.setAttribute( 'inert', '' );
				inertPageElements.push( child );
			}
		} );
	}

	function restorePage(): void {
		inertPageElements.forEach( ( pageElement ) => {
			pageElement.removeAttribute( 'inert' );
		} );
	}

	function getFocusableElements(): HTMLElement[] {
		return Array.from(
			dialogElement.querySelectorAll< HTMLElement >(
				'input, textarea, select, button:not([disabled]), [tabindex]:not([tabindex="-1"])'
			)
		);
	}

	function trapFocus( keyboardEvent: KeyboardEvent ): void {
		if ( keyboardEvent.key !== 'Tab' ) {
			return;
		}
		const focusableElements = getFocusableElements();
		if ( focusableElements.length === 0 ) {
			return;
		}
		const firstFocusableElement = focusableElements[ 0 ];
		const lastFocusableElement =
			focusableElements[ focusableElements.length - 1 ];
		if ( ! lastFocusableElement ) {
			return;
		}
		if ( keyboardEvent.shiftKey ) {
			if (
				dialogElement.ownerDocument.activeElement ===
				firstFocusableElement
			) {
				keyboardEvent.preventDefault();
				lastFocusableElement.focus();
			}
		} else if (
			dialogElement.ownerDocument.activeElement === lastFocusableElement
		) {
			keyboardEvent.preventDefault();
			firstFocusableElement.focus();
		}
	}

	const dialogForm = document.createElement( 'form' );
	dialogForm.className = 'rrze-appointment__overlay-form';
	dialogForm.noValidate = true;
	const formFields = document.createElement( 'div' );
	formFields.className = 'rrze-appointment__overlay-fields';

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
	nameInput.placeholder = i18n.namePlaceholder || 'First and last name';
	nameInput.value = booker.bookerName || '';
	nameInput.readOnly = Boolean( booker.bookerName );
	nameInput.required = true;
	nameLabel.appendChild( nameLabelText );
	nameLabel.appendChild( nameInput );

	const emailLabel = document.createElement( 'label' );
	emailLabel.className = 'rrze-appointment__overlay-label';
	const emailLabelText = document.createElement( 'span' );
	emailLabelText.textContent = `${ i18n.yourEmail || 'Email address' } (${
		i18n.required || 'required'
	})`;
	const emailInput = document.createElement( 'input' );
	emailInput.type = 'email';
	emailInput.id = `${ instanceId }-email`;
	emailInput.className = 'rrze-appointment__overlay-email';
	emailInput.autocomplete = 'email';
	emailInput.placeholder = 'name@example.com';
	emailInput.value = booker.bookerEmail || '';
	emailInput.readOnly = Boolean( booker.bookerEmail );
	emailInput.required = true;
	emailLabel.appendChild( emailLabelText );
	emailLabel.appendChild( emailInput );

	const waitlistLabel = document.createElement( 'label' );
	waitlistLabel.className =
		'rrze-appointment__overlay-waitlist rrze-appointment__overlay-label--wide';
	const waitlistCheckbox = document.createElement( 'input' );
	waitlistCheckbox.type = 'checkbox';
	waitlistCheckbox.className = 'rrze-appointment__overlay-waitlist-checkbox';
	waitlistLabel.appendChild( waitlistCheckbox );
	waitlistLabel.appendChild(
		document.createTextNode(
			` ${
				i18n.waitlist ||
				'Notify me if an earlier appointment becomes available.'
			}`
		)
	);

	const statusMessage = document.createElement( 'p' );
	statusMessage.id = statusId;
	statusMessage.className = 'rrze-appointment__overlay-status is-hidden';
	statusMessage.setAttribute( 'role', 'status' );
	statusMessage.setAttribute( 'aria-live', 'polite' );
	statusMessage.setAttribute( 'aria-atomic', 'true' );

	const dialogActions = document.createElement( 'div' );
	dialogActions.className = 'rrze-appointment__overlay-actions';
	const confirmButton = document.createElement( 'button' );
	confirmButton.type = 'submit';
	confirmButton.className = 'rrze-appointment__overlay-confirm';
	confirmButton.textContent = isOpeningNotification
		? i18n.notifyButton || 'Notify me'
		: i18n.book || 'Request appointment';
	const cancelButton = document.createElement( 'button' );
	cancelButton.type = 'button';
	cancelButton.className = 'rrze-appointment__overlay-cancel';
	cancelButton.textContent = i18n.cancel || 'Cancel';

	let isClosed = false;
	let isSubmitting = false;
	function closeBookingDialog(): void {
		if ( isClosed || isSubmitting ) {
			return;
		}
		isClosed = true;
		document.removeEventListener( 'keydown', handleDocumentKeyDown );
		document.body.style.overflow = previousBodyOverflow;
		restorePage();
		overlayElement.remove();
		if ( triggerButton?.isConnected && ! triggerButton.disabled ) {
			triggerButton.focus();
			return;
		}
		const replacementSlotButton = Array.from(
			form.querySelectorAll< HTMLButtonElement >(
				'.rrze-appointment__slot-button'
			)
		).find(
			( button ) =>
				button.dataset.slotValue === slotValue && ! button.disabled
		);
		const focusFallback =
			replacementSlotButton ||
			form.querySelector< HTMLElement >(
				'.rrze-appointment__day-slots:not(.is-hidden) .rrze-appointment__day-slots-title'
			) ||
			form.querySelector< HTMLElement >(
				'.rrze-appointment__calendar-month-title'
			);
		focusFallback?.focus();
	}

	function handleDocumentKeyDown( keyboardEvent: KeyboardEvent ): void {
		if ( keyboardEvent.key === 'Escape' ) {
			closeBookingDialog();
		}
	}

	function clearStatus(): void {
		statusMessage.textContent = '';
		statusMessage.className = 'rrze-appointment__overlay-status is-hidden';
		statusMessage.setAttribute( 'role', 'status' );
		statusMessage.setAttribute( 'aria-live', 'polite' );
	}

	function showStatus(
		message: string,
		type: 'error' | 'loading' | 'success'
	): void {
		statusMessage.textContent = message;
		statusMessage.className = `rrze-appointment__overlay-status is-${ type }`;
		statusMessage.setAttribute(
			'role',
			type === 'error' ? 'alert' : 'status'
		);
		statusMessage.setAttribute(
			'aria-live',
			type === 'error' ? 'assertive' : 'polite'
		);
	}

	function showFieldError( input: HTMLInputElement, message: string ): void {
		input.setAttribute( 'aria-invalid', 'true' );
		input.setAttribute( 'aria-describedby', statusId );
		input.setAttribute( 'aria-errormessage', statusId );
		showStatus( message, 'error' );
		input.focus();
	}

	[ nameInput, emailInput ].forEach( ( input ) => {
		const clearFieldError = () => {
			input.removeAttribute( 'aria-invalid' );
			input.removeAttribute( 'aria-describedby' );
			input.removeAttribute( 'aria-errormessage' );
			if ( statusMessage.classList.contains( 'is-error' ) ) {
				clearStatus();
			}
		};
		input.addEventListener( 'input', clearFieldError );
		input.addEventListener( 'change', clearFieldError );
	} );

	closeButton.addEventListener( 'click', closeBookingDialog );
	cancelButton.addEventListener( 'click', closeBookingDialog );
	overlayElement.addEventListener( 'click', ( mouseEvent ) => {
		if ( mouseEvent.target === overlayElement ) {
			closeBookingDialog();
		}
	} );
	overlayElement.addEventListener( 'keydown', trapFocus );
	document.addEventListener( 'keydown', handleDocumentKeyDown );

	dialogForm.addEventListener( 'submit', ( submitEvent ) => {
		submitEvent.preventDefault();
		if ( isSubmitting ) {
			return;
		}
		clearStatus();
		const bookerName = nameInput.value.trim();
		if ( ! bookerName ) {
			showFieldError(
				nameInput,
				i18n.nameRequired || 'Enter your name.'
			);
			return;
		}

		const bookerEmail = emailInput.value.trim();
		const hasValidEmailFormat = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
			bookerEmail
		);
		if ( ! bookerEmail || ! hasValidEmailFormat ) {
			showFieldError(
				emailInput,
				i18n.emailRequired || 'Enter a valid email address.'
			);
			return;
		}

		confirmButton.disabled = true;
		cancelButton.disabled = true;
		closeButton.disabled = true;
		isSubmitting = true;
		dialogForm.setAttribute( 'aria-busy', 'true' );
		showStatus(
			isOpeningNotification
				? i18n.notifySending || 'Saving notification…'
				: i18n.booking || 'Sending request…',
			'loading'
		);

		const bookingFormData = new FormData();
		bookingFormData.append(
			'action',
			isOpeningNotification
				? 'rrze_appointment_notify_opening'
				: 'rrze_appointment_book'
		);
		bookingFormData.append( 'nonce', window.rrze_appointment?.nonce || '' );
		bookingFormData.append( 'slot', slotValue );
		bookingFormData.append( 'post_id', form.dataset.postId || '0' );
		bookingFormData.append( 'block_id', form.dataset.blockId || '' );
		bookingFormData.append( 'booker_email', bookerEmail );
		bookingFormData.append( 'booker_name', bookerName );
		bookingFormData.append(
			'booker_waitlist',
			waitlistCheckbox.checked ? '1' : '0'
		);

		submitAppointment( bookingFormData )
			.then( ( bookingResponse ) => {
				if ( bookingResponse.success ) {
					const responseData =
						typeof bookingResponse.data === 'object'
							? bookingResponse.data
							: null;
					if ( isOpeningNotification && responseData?.redirectUrl ) {
						window.location.assign( responseData.redirectUrl );
						return;
					}
					if ( ! isOpeningNotification ) {
						onBookingSucceeded( slotValue );
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
					formFields.hidden = true;
					dialogIntroduction.hidden = true;
					dialogHeading.textContent = isOpeningNotification
						? i18n.notifySuccessTitle || 'Notification registered'
						: i18n.successTitle || 'Check your inbox';
					dialogHeading.tabIndex = -1;
					dialogElement.classList.add( 'is-success' );
					confirmButton.remove();
					cancelButton.textContent = i18n.close || 'Close';
					cancelButton.disabled = false;
					closeButton.disabled = false;
					dialogHeading.focus();
					return;
				}

				isSubmitting = false;
				dialogForm.removeAttribute( 'aria-busy' );
				const responseMessage =
					typeof bookingResponse.data === 'string'
						? bookingResponse.data
						: bookingResponse.data?.message;
				showStatus(
					responseMessage ||
						i18n.bookingError ||
						"We couldn't request this appointment. Please try again.",
					'error'
				);
				confirmButton.disabled = false;
				cancelButton.disabled = false;
				closeButton.disabled = false;
			} )
			.catch( () => {
				isSubmitting = false;
				dialogForm.removeAttribute( 'aria-busy' );
				showStatus(
					i18n.networkError ||
						'Connection problem. Check your internet connection and try again.',
					'error'
				);
				confirmButton.disabled = false;
				cancelButton.disabled = false;
				closeButton.disabled = false;
			} );
	} );

	dialogActions.appendChild( cancelButton );
	dialogActions.appendChild( confirmButton );
	formFields.appendChild( nameLabel );
	formFields.appendChild( emailLabel );
	if ( ! isOpeningNotification ) {
		formFields.appendChild( waitlistLabel );
	}
	dialogForm.appendChild( formFields );
	dialogForm.appendChild( statusMessage );
	dialogForm.appendChild( dialogActions );
	dialogElement.appendChild( dialogHeader );
	dialogElement.appendChild( dialogIntroduction );
	dialogElement.appendChild( appointmentSummary );
	dialogElement.appendChild( dialogForm );
	overlayElement.appendChild( dialogElement );
	document.body.appendChild( overlayElement );
	document.body.style.overflow = 'hidden';
	if ( ! nameInput.value ) {
		nameInput.focus();
	} else if ( ! emailInput.value ) {
		emailInput.focus();
	} else {
		confirmButton.focus();
	}
	isolateDialog();
}
