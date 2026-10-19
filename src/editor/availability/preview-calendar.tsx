import { Button, DateCalendar, DatePicker } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { seen, trash, unseen } from '@wordpress/icons';
import type { TimeSlot } from '../../scheduling/types';
import type { PreviewCalendarProps } from '../types';
import {
	formatDate,
	formatDateWithWeekdayDisplay,
	parseDateString,
} from '../../scheduling/dates';
import { groupSlotsByDate } from '../../scheduling/schedule';

interface DaySlotsProps {
	activeDate: string;
	slots: TimeSlot[];
	onAddSlot?: ( date: string ) => void;
	onToggleSlotVisibility?: ( slot: TimeSlot ) => void;
	onRemoveSlot?: ( slot: TimeSlot ) => void;
}

/**
 * Renders a day's appointment times with separate content and action areas.
 *
 * @param root0                        Component properties.
 * @param root0.activeDate             Selected calendar date.
 * @param root0.slots                  Appointments for this date, including hidden times.
 * @param root0.onAddSlot              Opens the manual appointment dialog.
 * @param root0.onToggleSlotVisibility Toggles visibility without removing an appointment.
 * @param root0.onRemoveSlot           Removes a manually added appointment.
 * @return Appointment cards for the selected date.
 */
function DaySlots( {
	activeDate,
	slots,
	onAddSlot,
	onToggleSlotVisibility,
	onRemoveSlot,
}: DaySlotsProps ) {
	if ( ! activeDate ) {
		return null;
	}

	/* translators: %s: appointment date. */
	const title = __( 'Appointments on %s', 'rrze-appointment' ).replace(
		'%s',
		formatDateWithWeekdayDisplay( activeDate )
	);
	/* translators: %d: number of appointment times. */
	const slotCount = __( 'Available times: %d', 'rrze-appointment' ).replace(
		'%d',
		String( slots.filter( ( slot ) => ! slot.isExcluded ).length )
	);

	return (
		<fieldset className="rrze-appointment__day-slots rrze-appointment-editor-slots">
			<legend className="rrze-appointment-editor-slots__title">
				{ title }
			</legend>
			<div className="rrze-appointment-editor-slots__toolbar">
				<span className="rrze-appointment-editor-slots__count">
					{ slotCount }
				</span>
				{ onAddSlot && (
					<Button
						className="rrze-appointment-editor-slots__add"
						icon="plus-alt2"
						variant="secondary"
						onClick={ () => onAddSlot( activeDate ) }
					>
						{ __( 'Add appointment time', 'rrze-appointment' ) }
					</Button>
				) }
			</div>

			{ slots.length > 0 ? (
				<ul className="rrze-appointment-editor-slots__list">
					{ slots.map( ( slot ) => {
						const visibilityLabelTemplate = slot.isExcluded
							? /* translators: 1: appointment start time, 2: appointment end time. */
							  __(
									'Show appointment from %1$s to %2$s',
									'rrze-appointment'
							  )
							: /* translators: 1: appointment start time, 2: appointment end time. */
							  __(
									'Hide appointment from %1$s to %2$s',
									'rrze-appointment'
							  );
						const visibilityLabel = visibilityLabelTemplate
							.replace( '%1$s', slot.startTime )
							.replace( '%2$s', slot.endTime );

						return (
							<li
								className={ `rrze-appointment-editor-slots__item ${
									slot.isExcluded ? 'is-excluded' : ''
								}` }
								key={ slot.value }
							>
								<span
									aria-hidden="true"
									className="dashicons dashicons-clock rrze-appointment-editor-slots__icon"
								/>
								<div className="rrze-appointment-editor-slots__content">
									<span className="rrze-appointment-editor-slots__time">
										<strong>{ slot.startTime }</strong>
										<span
											aria-hidden="true"
											className="rrze-appointment-editor-slots__separator"
										>
											–
										</span>
										<strong>{ slot.endTime }</strong>
									</span>
									{ slot.isExtra && (
										<span className="rrze-appointment-editor-slots__badge">
											{ __(
												'Added manually',
												'rrze-appointment'
											) }
										</span>
									) }
									{ slot.isExcluded && (
										<span className="rrze-appointment-editor-slots__badge is-excluded">
											{ __(
												'Not visible',
												'rrze-appointment'
											) }
										</span>
									) }
								</div>
								<div className="rrze-appointment-editor-slots__actions">
									{ onToggleSlotVisibility && (
										<Button
											type="button"
											className="rrze-appointment-editor-slots__visibility"
											label={ visibilityLabel }
											icon={
												slot.isExcluded ? seen : unseen
											}
											variant="tertiary"
											onClick={ () =>
												onToggleSlotVisibility( slot )
											}
										/>
									) }
									{ slot.isExtra && onRemoveSlot && (
										<Button
											type="button"
											label={
												/* translators: 1: appointment start time, 2: appointment end time. */
												__(
													'Remove manual appointment from %1$s to %2$s',
													'rrze-appointment'
												)
													.replace(
														'%1$s',
														slot.startTime
													)
													.replace(
														'%2$s',
														slot.endTime
													)
											}
											icon={ trash }
											variant="tertiary"
											isDestructive
											onClick={ () =>
												onRemoveSlot( slot )
											}
										/>
									) }
								</div>
							</li>
						);
					} ) }
				</ul>
			) : (
				<div className="rrze-appointment-editor-slots__empty">
					<span
						aria-hidden="true"
						className="dashicons dashicons-clock"
					/>
					<div>
						<strong>
							{ __(
								'No appointments on this date',
								'rrze-appointment'
							) }
						</strong>
						<p>
							{ __(
								'Add an appointment time to make this date bookable.',
								'rrze-appointment'
							) }
						</p>
					</div>
				</div>
			) }
		</fieldset>
	);
}

/**
 * Displays the calendar and editable appointments for its selected day.
 *
 * @param root0                        Component properties.
 * @param root0.slots                  Generated and manual appointments.
 * @param root0.selectedDates          Dates available in the calendar.
 * @param root0.onToggleSlotVisibility Toggles visibility for an appointment.
 * @param root0.onRemoveSlot           Removes a manually added appointment.
 * @param root0.onAddSlot              Opens the manual appointment dialog.
 * @param root0.activeDate             Selected calendar date.
 * @param root0.onActiveDateChange     Updates the selected calendar date.
 * @param root0.hideWeekends           Whether weekends are disabled.
 * @return Calendar and appointment cards.
 */
export function PreviewCalendar( {
	slots,
	selectedDates,
	onToggleSlotVisibility,
	onRemoveSlot,
	onAddSlot,
	activeDate,
	onActiveDateChange,
	hideWeekends,
}: PreviewCalendarProps ) {
	const groupedSlots = useMemo( () => groupSlotsByDate( slots ), [ slots ] );
	const dates = useMemo(
		() => [ ...selectedDates ].sort(),
		[ selectedDates ]
	);
	const firstDate = parseDateString( dates[ 0 ] );
	const selectedDate =
		parseDateString( activeDate ) || firstDate || undefined;
	const availableDates = useMemo( () => new Set( dates ), [ dates ] );
	const [ viewDate, setViewDate ] = useState(
		() => selectedDate || new Date()
	);

	useEffect( () => {
		const date =
			parseDateString( activeDate ) || parseDateString( dates[ 0 ] );
		if ( date ) {
			setViewDate( date );
		}
	}, [ activeDate, dates ] );

	if ( dates.length === 0 || ! firstDate || ! selectedDate ) {
		return null;
	}

	const isUnavailable = ( date: Date ) =>
		! availableDates.has( formatDate( date ) ) ||
		( hideWeekends && ( date.getDay() === 0 || date.getDay() === 6 ) );
	const selectDate = ( date?: Date ) => {
		if ( date && ! isUnavailable( date ) ) {
			onActiveDateChange( formatDate( date ) );
		}
	};

	return (
		<>
			<div className="rrze-appointment-block__date-calendar">
				{ DateCalendar ? (
					<DateCalendar
						disabled={ isUnavailable }
						month={ viewDate }
						onMonthChange={ setViewDate }
						onSelect={ selectDate }
						required
						selected={ selectedDate }
						weekStartsOn={ 1 }
					/>
				) : (
					<DatePicker
						currentDate={ activeDate }
						isInvalidDate={ isUnavailable }
						onChange={ ( value ) => {
							const date = parseDateString(
								value.slice( 0, 10 )
							);
							selectDate( date || undefined );
						} }
						startOfWeek={ 1 }
					/>
				) }
			</div>
			<DaySlots
				activeDate={ activeDate }
				slots={ activeDate ? groupedSlots[ activeDate ] || [] : [] }
				onAddSlot={ onAddSlot }
				onToggleSlotVisibility={ onToggleSlotVisibility }
				onRemoveSlot={ onRemoveSlot }
			/>
		</>
	);
}
