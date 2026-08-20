function renderAppointment(
	bookedSlots: string[] = [],
	bookingMaxAdvance = 0
): HTMLFormElement {
	document.body.innerHTML = `
		<form
			class="rrze-appointment"
			data-disable-sso="1"
			data-hide-weekends="0"
			data-post-id="1"
			data-block-id="test"
			data-booking-max-advance="${ bookingMaxAdvance }"
		>
			<fieldset>
				<legend>Office hours</legend>
				<div class="rrze-appointment__calendar"></div>
				<p class="rrze-appointment__availability-status is-hidden" role="status" aria-live="polite"></p>
				<div class="rrze-appointment__day-slots is-hidden">
					<h3 class="rrze-appointment__day-slots-title">Times on selected day</h3>
					<div class="rrze-appointment__day-slots-list"></div>
				</div>
				<div class="rrze-appointment__slot-data" hidden aria-hidden="true">
					<input type="radio" name="rrze_appointment_slot" value="2099-01-20 10:00-10:30" data-label="10:00 - 10:30">
					<input type="radio" name="rrze_appointment_slot" value="2099-02-02 14:00-14:30" data-label="14:00 - 14:30">
				</div>
				<p class="rrze-appointment__selected-info is-hidden" role="status" aria-live="polite"></p>
			</fieldset>
		</form>`;

	window.rrze_appointment = {
		bookedSlots,
		locale: 'en-GB',
		i18n: {
			available: 'available appointments',
			availableOn: 'Available appointments on %s',
			appointmentsOn: 'Appointments on %s',
			bookingAdvanceDay:
				'These appointments can only be booked %d day in advance.',
			bookingAdvanceDays:
				'These appointments can only be booked %d days in advance.',
			chooseDate: 'Choose an appointment date',
			nextMonth: 'Next month',
			noSlotsAvailable: 'No time slots available.',
			previousMonth: 'Previous month',
			required: 'required',
			selected: 'selected',
			today: 'today',
			unavailable: 'no available appointments',
			notOpen: 'appointments not yet bookable',
		},
	};

	jest.resetModules();
	require( '../src/view' );
	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

	return document.querySelector< HTMLFormElement >(
		'form.rrze-appointment'
	) as HTMLFormElement;
}

describe( 'frontend calendar accessibility', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		delete window.rrze_appointment;
		jest.useRealTimers();
	} );

	it( 'provides localized control names and preserves meaningful focus', () => {
		const form = renderAppointment();
		const weekdayLabels = Array.from(
			form.querySelectorAll( '.rrze-appointment__weekday' )
		).map( ( weekday ) => weekday.textContent );
		expect( weekdayLabels ).toEqual( [
			'Mon',
			'Tue',
			'Wed',
			'Thu',
			'Fri',
			'Sat',
			'Sun',
		] );
		const nextButton = form.querySelector< HTMLButtonElement >(
			'[data-direction="next"]'
		) as HTMLButtonElement;
		expect( nextButton.getAttribute( 'aria-label' ) ).toContain(
			'Next month'
		);

		nextButton.focus();
		nextButton.click();
		expect( nextButton.ownerDocument.activeElement ).toBe(
			form.querySelector( '.rrze-appointment__calendar-month-title' )
		);

		const availableDay = form.querySelector< HTMLButtonElement >(
			'.rrze-appointment__calendar-day.is-available'
		) as HTMLButtonElement;
		expect( availableDay.getAttribute( 'aria-label' ) ).toContain(
			'available appointments'
		);
		expect( availableDay.getAttribute( 'aria-label' ) ).toContain(
			'February 2099'
		);

		availableDay.focus();
		availableDay.click();
		expect( availableDay.ownerDocument.activeElement ).toBe(
			form.querySelector( '.rrze-appointment__day-slots-title' )
		);
		expect( availableDay.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
		expect(
			form
				.querySelector( '.rrze-appointment__calendar-day.is-active' )
				?.getAttribute( 'aria-pressed' )
		).toBe( 'true' );
		const selectedDateSlot = form.querySelector< HTMLButtonElement >(
			'.rrze-appointment__slot-button'
		) as HTMLButtonElement;
		expect( selectedDateSlot.getAttribute( 'aria-label' ) ).toContain(
			'2 February 2099'
		);
		expect( selectedDateSlot.getAttribute( 'aria-label' ) ).not.toContain(
			'20 January 2099'
		);
	} );

	it( 'announces when filtering leaves no available appointments', () => {
		const form = renderAppointment( [
			'2099-01-20 10:00-10:30',
			'2099-02-02 14:00-14:30',
		] );
		const status = form.querySelector(
			'.rrze-appointment__availability-status'
		);

		expect( status?.textContent ).toBe( 'No time slots available.' );
		expect( status?.classList.contains( 'is-hidden' ) ).toBe( false );
	} );

	it( 'shows appointments outside the advance window as not yet bookable', () => {
		jest.useFakeTimers();
		jest.setSystemTime( new Date( '2099-01-10T09:00:00' ) );

		const form = renderAppointment( [], 14 * 24 * 60 );
		const availableDays = Array.from(
			form.querySelectorAll< HTMLButtonElement >(
				'.rrze-appointment__calendar-day.is-available'
			)
		);

		expect( availableDays ).toHaveLength( 1 );
		expect( availableDays[ 0 ].getAttribute( 'aria-label' ) ).toContain(
			'20 January 2099'
		);

		form
			.querySelector< HTMLButtonElement >( '[data-direction="next"]' )
			?.click();
		const notOpenDay = form.querySelector< HTMLButtonElement >(
			'.rrze-appointment__calendar-day.is-not-open'
		) as HTMLButtonElement;
		expect( notOpenDay.disabled ).toBe( false );
		expect( notOpenDay.getAttribute( 'aria-label' ) ).toContain(
			'appointments not yet bookable'
		);

		notOpenDay.click();
		const notOpenSlot = form.querySelector< HTMLButtonElement >(
			'.rrze-appointment__slot-button.is-not-open'
		) as HTMLButtonElement;
		expect( notOpenSlot.disabled ).toBe( true );
		expect( notOpenSlot.getAttribute( 'aria-label' ) ).toContain(
			'These appointments can only be booked 14 days in advance.'
		);
		expect(
			form.querySelector( '.rrze-appointment__booking-window-notice' )
				?.textContent
		).toBe( 'These appointments can only be booked 14 days in advance.' );
		expect(
			form.querySelector( '.rrze-appointment__day-slots-title' )
				?.textContent
		).toContain( 'Appointments on' );
	} );

	it( 'isolates the modal, associates errors, and restores focus', () => {
		const form = renderAppointment();
		const slotButton = form.querySelector< HTMLButtonElement >(
			'.rrze-appointment__slot-button'
		) as HTMLButtonElement;
		expect(
			form.querySelector( '.rrze-appointment__day-slots' )?.tagName
		).toBe( 'DIV' );
		expect( slotButton.getAttribute( 'aria-label' ) ).toContain(
			'10:00 - 10:30'
		);
		expect( slotButton.getAttribute( 'aria-label' ) ).toContain(
			'20 January 2099'
		);

		slotButton.click();
		expect( form.hasAttribute( 'inert' ) ).toBe( true );
		expect( document.querySelector( '[role="dialog"]' ) ).not.toBeNull();

		const dialogForm = document.querySelector< HTMLFormElement >(
			'.rrze-appointment__overlay-form'
		) as HTMLFormElement;
		expect( dialogForm.querySelector( 'textarea' ) ).toBeNull();
		dialogForm.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
		const nameInput = dialogForm.querySelector< HTMLInputElement >(
			'.rrze-appointment__overlay-name'
		) as HTMLInputElement;
		expect( nameInput.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( nameInput.getAttribute( 'aria-describedby' ) ).toBe(
			'rrze-appt-1-dialog-status'
		);

		document.dispatchEvent(
			new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
		);
		expect( form.hasAttribute( 'inert' ) ).toBe( false );
		expect( slotButton.ownerDocument.activeElement ).toBe( slotButton );
	} );
} );
