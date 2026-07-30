import { Button } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import type { CalendarMultiSelectProps } from '../types';
import { formatDate, parseDateString } from '../utils';

const WEEKDAY_NAMES = [ 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So' ];

export function CalendarMultiSelect( {
	selectedDates,
	activeDate,
	onToggleDate,
}: CalendarMultiSelectProps ) {
	const today = new Date();
	const todayString = formatDate( today );
	const selectedSet = useMemo(
		() => new Set( selectedDates ),
		[ selectedDates ]
	);
	const latestSelectedDate = parseDateString(
		selectedDates[ selectedDates.length - 1 ]
	);
	const [ viewDate, setViewDate ] = useState(
		() =>
			new Date(
				latestSelectedDate?.getFullYear() ?? today.getFullYear(),
				latestSelectedDate?.getMonth() ?? today.getMonth(),
				1
			)
	);

	const year = viewDate.getFullYear();
	const month = viewDate.getMonth();
	const daysInMonth = new Date( year, month + 1, 0 ).getDate();
	const leadingEmptyDays = ( new Date( year, month, 1 ).getDay() + 6 ) % 7;

	return (
		<div className="rrze-appointment-block__calendar">
			<div className="rrze-appointment-block__calendar-header">
				<Button
					variant="secondary"
					isSmall
					onClick={ () =>
						setViewDate( new Date( year, month - 1, 1 ) )
					}
				>
					{ '<' }
				</Button>
				<strong>
					{ viewDate.toLocaleDateString( 'de-DE', {
						month: 'long',
						year: 'numeric',
					} ) }
				</strong>
				<Button
					variant="secondary"
					isSmall
					onClick={ () =>
						setViewDate( new Date( year, month + 1, 1 ) )
					}
				>
					{ '>' }
				</Button>
			</div>
			<div className="rrze-appointment-block__calendar-grid">
				{ WEEKDAY_NAMES.map( ( name ) => (
					<div
						key={ name }
						className="rrze-appointment-block__calendar-weekday"
					>
						{ name }
					</div>
				) ) }
				{ Array.from( { length: leadingEmptyDays }, ( _, index ) => (
					<div key={ `empty-${ index }` } />
				) ) }
				{ Array.from( { length: daysInMonth }, ( _, index ) => {
					const day = index + 1;
					const dayDate = new Date( year, month, day );
					const dateString = formatDate( dayDate );
					const isPast = dateString < todayString;
					const dayOfWeek = dayDate.getDay();

					return (
						<button
							key={ dateString }
							type="button"
							onClick={ () => onToggleDate( dateString ) }
							aria-label={ dateString }
							disabled={ isPast }
							className={ [
								'rrze-appointment__calendar-day',
								isPast ? 'is-past' : '',
								selectedSet.has( dateString )
									? 'is-available'
									: '',
								dateString === todayString ? 'is-today' : '',
								dateString === activeDate ? 'is-active' : '',
								dayOfWeek === 0 || dayOfWeek === 6
									? 'is-weekend'
									: '',
							]
								.filter( Boolean )
								.join( ' ' ) }
						>
							{ day }
						</button>
					);
				} ) }
			</div>
		</div>
	);
}
