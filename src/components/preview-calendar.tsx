import { Button, DateCalendar, DatePicker } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { PreviewCalendarProps, TimeSlot } from '../types';
import {
	formatDate,
	formatDateDisplay,
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
	if ( ! activeDate || slots.length === 0 ) {
		return null;
	}

	/* translators: %s: appointment date. */
	const title = __( 'Times on %s', 'rrze-appointment' ).replace(
		'%s',
		formatDateDisplay( activeDate )
	);

	return (
		<fieldset className="rrze-appointment__day-slots">
			<p className="rrze-appointment__day-slots-title">{ title }</p>
			<div className="rrze-appointment__day-slots-list rrze-appointment__slot-grid">
				{ slots.map( ( slot ) => (
					<div
						className="rrze-appointment__slot-item"
						key={ slot.value }
					>
						<Button
							className="rrze-appointment__slot-button"
							disabled
						>
							{ slot.timeRange }
						</Button>
						{ onRemoveSlot && (
							<Button
								className="rrze-appointment__slot-delete"
								label={ __(
									'Delete time slot',
									'rrze-appointment'
								) }
								icon="no-alt"
								isDestructive
								onClick={ () => onRemoveSlot( slot ) }
							/>
						) }
					</div>
				) ) }
				{ onAddSlot && (
					<Button
						className="rrze-appointment__slot-add"
						label={ __( 'Add time slot', 'rrze-appointment' ) }
						icon="plus-alt2"
						onClick={ () => onAddSlot( activeDate ) }
					/>
				) }
			</div>
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
