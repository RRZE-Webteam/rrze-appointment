import { Button, DateCalendar, DatePicker } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { seen, unseen } from '@wordpress/icons';
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
}

function DaySlots( {
	activeDate,
	slots,
	onAddSlot,
	onToggleSlotVisibility,
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
								{ onToggleSlotVisibility && (
									<Button
										className="rrze-appointment-editor-slots__visibility"
										label={ visibilityLabel }
										icon={ slot.isExcluded ? seen : unseen }
										variant="tertiary"
										onClick={ () =>
											onToggleSlotVisibility( slot )
										}
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
	onToggleSlotVisibility,
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
			/>
		</>
	);
}
