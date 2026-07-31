import { Button, DateCalendar, DatePicker } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { PreviewCalendarProps, TimeSlot } from '../types';
import {
	formatDate,
	formatDateWithWeekdayDisplay,
	groupSlotsByDate,
	parseDateString,
} from '../utils';

interface DaySlotsProps {
	activeDate: string;
	slots: TimeSlot[];
	onAddSlot?: ( date: string ) => void;
	onRemoveSlot?: ( slot: TimeSlot ) => void;
}

function DaySlots( {
	activeDate,
	slots,
	onAddSlot,
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
		String( slots.length )
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
						/* translators: 1: appointment start time, 2: appointment end time. */
						const removeLabel = __(
							'Remove appointment from %1$s to %2$s',
							'rrze-appointment'
						)
							.replace( '%1$s', slot.startTime )
							.replace( '%2$s', slot.endTime );

						return (
							<li
								className="rrze-appointment-editor-slots__item"
								key={ slot.value }
							>
								<span
									aria-hidden="true"
									className="dashicons dashicons-clock rrze-appointment-editor-slots__icon"
								/>
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
								{ onRemoveSlot && (
									<Button
										className="rrze-appointment-editor-slots__remove"
										label={ removeLabel }
										icon="trash"
										isDestructive
										variant="tertiary"
										onClick={ () => onRemoveSlot( slot ) }
									/>
								) }
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

export function PreviewCalendar( {
	slots,
	selectedDates,
	onRemoveSlot,
	onAddSlot,
	activeDate,
	setActiveDate,
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
			setActiveDate( formatDate( date ) );
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
				onRemoveSlot={ onRemoveSlot }
			/>
		</>
	);
}
