import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
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
	isDateSelectionMode: boolean;
	isWeekend?: boolean;
	onSelectDate: ( date: string ) => void;
	onToggleDate?: ( date: string ) => void;
	today: string;
}

function CalendarDayButton( {
	activeDate,
	availableDates,
	dateString,
	day,
	isDateSelectionMode,
	isWeekend = false,
	onSelectDate,
	onToggleDate,
	today,
}: CalendarDayButtonProps ) {
	const isAvailable = availableDates.has( dateString );
	const isPast = dateString < today;

	return (
		<button
			type="button"
			className={ [
				'rrze-appointment__calendar-day',
				isPast ? 'is-past' : '',
				isWeekend ? 'is-weekend' : '',
				dateString === today ? 'is-today' : '',
				isAvailable ? 'is-available' : '',
				dateString === activeDate ? 'is-active' : '',
				isDateSelectionMode ? 'is-selection-mode' : '',
			]
				.filter( Boolean )
				.join( ' ' ) }
			disabled={
				isDateSelectionMode ? isPast || ! onToggleDate : ! isAvailable
			}
			aria-pressed={ isDateSelectionMode ? isAvailable : undefined }
			onClick={ () => {
				if ( isDateSelectionMode ) {
					onToggleDate?.( dateString );
					return;
				}
				onSelectDate( dateString );
			} }
		>
			{ day }
		</button>
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
	isDateSelectionMode: boolean;
	monthIndex: number;
	onSelectDate: ( date: string ) => void;
	onToggleDate?: ( date: string ) => void;
	today: string;
	year: number;
}

function MonthGrid( {
	activeDate,
	availableDates,
	hideWeekends,
	isDateSelectionMode,
	monthIndex,
	onSelectDate,
	onToggleDate,
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
						isDateSelectionMode={ isDateSelectionMode }
						isWeekend={
							! hideWeekends &&
							( dayOfWeek === 0 || dayOfWeek === 6 )
						}
						onSelectDate={ onSelectDate }
						onToggleDate={ onToggleDate }
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
						<button
							type="button"
							className="rrze-appointment__slot-button"
						>
							{ slot.timeRange }
						</button>
						{ onRemoveSlot && (
							<button
								type="button"
								className="rrze-appointment__slot-delete"
								aria-label={ `Uhrzeit ${ slot.timeRange } löschen` }
								onClick={ () => onRemoveSlot( slot ) }
							>
								<svg
									xmlns="http://www.w3.org/2000/svg"
									viewBox="0 0 24 24"
									width="16"
									height="16"
									aria-hidden="true"
									focusable="false"
								>
									<line
										x1="5"
										y1="5"
										x2="19"
										y2="19"
										stroke="currentColor"
										strokeWidth="2.5"
										strokeLinecap="round"
									/>
									<line
										x1="19"
										y1="5"
										x2="5"
										y2="19"
										stroke="currentColor"
										strokeWidth="2.5"
										strokeLinecap="round"
									/>
								</svg>
							</button>
						) }
					</div>
				) ) }
				{ onAddSlot && (
					<button
						type="button"
						className="rrze-appointment__slot-add"
						aria-label={ `Uhrzeit am ${ formatDateDisplay(
							activeDate
						) } hinzufügen` }
						onClick={ () => onAddSlot( activeDate ) }
					>
						+
					</button>
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
	onToggleDate,
	activeDate,
	setActiveDate,
	hideWeekends,
	isDateSelectionMode = false,
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

	if ( ( dates.length === 0 || ! firstDate ) && ! isDateSelectionMode ) {
		return null;
	}

	const year = viewDate.getFullYear();
	const monthIndex = viewDate.getMonth();

	return (
		<>
			{ isDateSelectionMode && (
				<p className="rrze-appointment-block__calendar-instructions">
					{ __(
						'Click a date to add or remove it.',
						'rrze-appointment'
					) }
				</p>
			) }
			<div className="rrze-appointment__calendar">
				<div className="rrze-appointment__calendar-month">
					<div className="rrze-appointment__calendar-title">
						<button
							type="button"
							onClick={ () =>
								setViewDate(
									new Date( year, monthIndex - 1, 1 )
								)
							}
							className="rrze-appointment__calendar-nav"
						>
							{ '‹' }
						</button>
						<span>
							{ viewDate.toLocaleDateString( 'de-DE', {
								month: 'long',
								year: 'numeric',
							} ) }
						</span>
						<button
							type="button"
							onClick={ () =>
								setViewDate(
									new Date( year, monthIndex + 1, 1 )
								)
							}
							className="rrze-appointment__calendar-nav"
						>
							{ '›' }
						</button>
					</div>
					<MonthGrid
						activeDate={ activeDate }
						availableDates={ availableDates }
						hideWeekends={ hideWeekends }
						isDateSelectionMode={ isDateSelectionMode }
						monthIndex={ monthIndex }
						onSelectDate={ setActiveDate }
						onToggleDate={ onToggleDate }
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
