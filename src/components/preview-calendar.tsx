import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import type {
	PreviewCalendarProps,
	TimeSlot,
	WeekdayMonthGridCell,
} from '../types';
import {
	formatDate,
	formatDateDisplay,
	getWeekdayMonthGridCells,
	groupSlotsByDate,
	parseDateString,
} from '../utils';

const WEEKDAY_NAMES = [ 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So' ];

interface CalendarDayButtonProps {
	activeDate: string;
	availableDates: Set< string >;
	dateString: string;
	day: number;
	isWeekend?: boolean;
	onSelectDate: ( date: string ) => void;
	today: string;
}

function CalendarDayButton( {
	activeDate,
	availableDates,
	dateString,
	day,
	isWeekend = false,
	onSelectDate,
	today,
}: CalendarDayButtonProps ) {
	const isAvailable = availableDates.has( dateString );
	const isPast = dateString < today;

	return (
		<Button
			className={ [
				'rrze-appointment__calendar-day',
				isPast ? 'is-past' : '',
				isWeekend ? 'is-weekend' : '',
				dateString === today ? 'is-today' : '',
				isAvailable ? 'is-available' : '',
				dateString === activeDate ? 'is-active' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			disabled={ ! isAvailable }
			onClick={ () => onSelectDate( dateString ) }
		>
			{ day }
		</Button>
	);
}

function getFullMonthGridCells(
	year: number,
	monthIndex: number
): WeekdayMonthGridCell[] {
	const leadingEmptyDays =
		( new Date( year, monthIndex, 1 ).getDay() + 6 ) % 7;
	const daysInMonth = new Date( year, monthIndex + 1, 0 ).getDate();

	return [
		...Array.from(
			{ length: leadingEmptyDays },
			(): WeekdayMonthGridCell => ( { type: 'empty' } )
		),
		...Array.from( { length: daysInMonth }, ( _, index ) => {
			const date = new Date( year, monthIndex, index + 1 );
			return {
				type: 'day' as const,
				day: index + 1,
				dateString: formatDate( date ),
			};
		} ),
	];
}

interface MonthGridProps {
	activeDate: string;
	availableDates: Set< string >;
	hideWeekends: boolean;
	monthIndex: number;
	onSelectDate: ( date: string ) => void;
	today: string;
	year: number;
}

function MonthGrid( {
	activeDate,
	availableDates,
	hideWeekends,
	monthIndex,
	onSelectDate,
	today,
	year,
}: MonthGridProps ) {
	const cells = hideWeekends
		? getWeekdayMonthGridCells( year, monthIndex )
		: getFullMonthGridCells( year, monthIndex );
	const weekdayNames = hideWeekends
		? WEEKDAY_NAMES.slice( 0, 5 )
		: WEEKDAY_NAMES;

	return (
		<div
			className={ [
				'rrze-appointment__calendar-grid',
				hideWeekends ? 'is-hide-weekends' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
		>
			{ weekdayNames.map( ( weekday ) => (
				<div className="rrze-appointment__weekday" key={ weekday }>
					{ weekday }
				</div>
			) ) }
			{ cells.map( ( cell, index ) => {
				if ( cell.type === 'empty' ) {
					return (
						<div
							className="rrze-appointment__calendar-empty"
							key={ `empty-${ index }` }
						/>
					);
				}

				const dayOfWeek = new Date(
					year,
					monthIndex,
					cell.day
				).getDay();

				return (
					<CalendarDayButton
						key={ cell.dateString }
						activeDate={ activeDate }
						availableDates={ availableDates }
						dateString={ cell.dateString }
						day={ cell.day }
						isWeekend={
							! hideWeekends &&
							( dayOfWeek === 0 || dayOfWeek === 6 )
						}
						onSelectDate={ onSelectDate }
						today={ today }
					/>
				);
			} ) }
		</div>
	);
}

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
	const availableDates = useMemo( () => new Set( dates ), [ dates ] );
	const [ viewDate, setViewDate ] = useState( () => {
		if ( ! firstDate ) {
			return new Date();
		}
		return new Date( firstDate.getFullYear(), firstDate.getMonth(), 1 );
	} );

	useEffect( () => {
		const date = parseDateString( activeDate );
		if ( ! date ) {
			return;
		}
		setViewDate( ( previousDate ) => {
			if (
				previousDate.getFullYear() === date.getFullYear() &&
				previousDate.getMonth() === date.getMonth()
			) {
				return previousDate;
			}
			return new Date( date.getFullYear(), date.getMonth(), 1 );
		} );
	}, [ activeDate ] );

	if ( dates.length === 0 || ! firstDate ) {
		return null;
	}

	const year = viewDate.getFullYear();
	const monthIndex = viewDate.getMonth();

	return (
		<>
			<div className="rrze-appointment__calendar">
				<div className="rrze-appointment__calendar-month">
					<div className="rrze-appointment__calendar-title">
						<Button
							label={ __( 'Previous month', 'rrze-appointment' ) }
							onClick={ () =>
								setViewDate(
									new Date( year, monthIndex - 1, 1 )
								)
							}
							className="rrze-appointment__calendar-nav"
						>
							{ '‹' }
						</Button>
						<span>
							{ viewDate.toLocaleDateString( 'de-DE', {
								month: 'long',
								year: 'numeric',
							} ) }
						</span>
						<Button
							label={ __( 'Next month', 'rrze-appointment' ) }
							onClick={ () =>
								setViewDate(
									new Date( year, monthIndex + 1, 1 )
								)
							}
							className="rrze-appointment__calendar-nav"
						>
							{ '›' }
						</Button>
					</div>
					<MonthGrid
						activeDate={ activeDate }
						availableDates={ availableDates }
						hideWeekends={ hideWeekends }
						monthIndex={ monthIndex }
						onSelectDate={ setActiveDate }
						today={ formatDate( new Date() ) }
						year={ year }
					/>
				</div>
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
