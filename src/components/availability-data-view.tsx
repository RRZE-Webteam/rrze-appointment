import {
	DataViews,
	filterSortAndPaginate,
	type Action,
	type Field,
	type View,
} from '@wordpress/dataviews/wp';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { pencil, trash } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import { getAvailabilityDates } from '../availability';
import relaxingIllustration from '../illustrations/relaxing-55-3.png';
import type { AvailabilityEntry, RecurrenceWeekday } from '../types';
import { formatDateWithWeekdayDisplay, parseDateString } from '../utils';

interface AvailabilityDataViewProps {
	entries: AvailabilityEntry[];
	onAdd: () => void;
	onDelete: ( entry: AvailabilityEntry ) => void;
	onEdit: ( entry: AvailabilityEntry ) => void;
}

interface WeekdayDefinition {
	abbreviation: string;
	label: string;
	value: RecurrenceWeekday;
}

const INITIAL_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 10,
	sort: {
		field: 'date',
		direction: 'asc',
	},
	titleField: 'date',
	fields: [ 'time', 'slotPattern', 'weekdays' ],
	layout: {
		density: 'comfortable',
		styles: {
			date: { minWidth: '180px' },
			time: { minWidth: '110px' },
			slotPattern: { minWidth: '150px' },
			weekdays: { minWidth: '250px' },
		},
	},
};

function getWeekdays(): WeekdayDefinition[] {
	return [
		{
			value: 1,
			abbreviation: __( 'Mon', 'rrze-appointment' ),
			label: __( 'Monday', 'rrze-appointment' ),
		},
		{
			value: 2,
			abbreviation: __( 'Tue', 'rrze-appointment' ),
			label: __( 'Tuesday', 'rrze-appointment' ),
		},
		{
			value: 3,
			abbreviation: __( 'Wed', 'rrze-appointment' ),
			label: __( 'Wednesday', 'rrze-appointment' ),
		},
		{
			value: 4,
			abbreviation: __( 'Thu', 'rrze-appointment' ),
			label: __( 'Thursday', 'rrze-appointment' ),
		},
		{
			value: 5,
			abbreviation: __( 'Fri', 'rrze-appointment' ),
			label: __( 'Friday', 'rrze-appointment' ),
		},
		{
			value: 6,
			abbreviation: __( 'Sat', 'rrze-appointment' ),
			label: __( 'Saturday', 'rrze-appointment' ),
		},
		{
			value: 0,
			abbreviation: __( 'Sun', 'rrze-appointment' ),
			label: __( 'Sunday', 'rrze-appointment' ),
		},
	];
}

function getRecurrenceLabel( entry: AvailabilityEntry ): string {
	switch ( entry.recurrence.freq ) {
		case 'daily':
			return __( 'Daily', 'rrze-appointment' );
		case 'weekly':
			return __( 'Weekly', 'rrze-appointment' );
		case 'monthly':
			return __( 'Monthly', 'rrze-appointment' );
		default:
			return __( 'Does not repeat', 'rrze-appointment' );
	}
}

function getActiveWeekdays(
	entry: AvailabilityEntry
): Set< RecurrenceWeekday > {
	if ( ! entry.recurrence.freq ) {
		return new Set();
	}

	return new Set(
		getAvailabilityDates( entry )
			.map( ( date ) => parseDateString( date ) )
			.filter( ( date ): date is Date => date !== null )
			.map( ( date ) => date.getDay() as RecurrenceWeekday )
	);
}

function WeekdayLabels( { item }: { item: AvailabilityEntry } ) {
	const activeWeekdays = getActiveWeekdays( item );

	return (
		<div className="rrze-appointment-data-view__recurrence">
			<span className="rrze-appointment-data-view__recurrence-label">
				{ getRecurrenceLabel( item ) }
			</span>
			<div className="rrze-appointment-data-view__weekdays">
				{ getWeekdays().map( ( weekday ) => {
					const isActive = activeWeekdays.has( weekday.value );
					const stateLabel = isActive
						? sprintf(
								/* translators: %s: weekday name. */
								__( 'Repeats on %s', 'rrze-appointment' ),
								weekday.label
						  )
						: sprintf(
								/* translators: %s: weekday name. */
								__(
									'Does not repeat on %s',
									'rrze-appointment'
								),
								weekday.label
						  );

					return (
						<span
							aria-label={ stateLabel }
							className={ `rrze-appointment-data-view__weekday ${
								isActive ? 'is-active' : 'is-inactive'
							}` }
							key={ weekday.value }
							title={ stateLabel }
						>
							{ weekday.abbreviation }
						</span>
					);
				} ) }
			</div>
		</div>
	);
}

function DateField( { item }: { item: AvailabilityEntry } ) {
	return <strong>{ formatDateWithWeekdayDisplay( item.date ) }</strong>;
}

function TimeField( { item }: { item: AvailabilityEntry } ) {
	return (
		<span>
			{ item.startTime }–{ item.endTime }
		</span>
	);
}

function SlotPatternField( { item }: { item: AvailabilityEntry } ) {
	return (
		<span>
			{ item.duration } min
			{ item.breakDuration > 0
				? ` · ${ __( 'Break', 'rrze-appointment' ) } ${
						item.breakDuration
				  } min`
				: '' }
		</span>
	);
}

export function AvailabilityDataView( {
	entries,
	onAdd,
	onDelete,
	onEdit,
}: AvailabilityDataViewProps ) {
	const [ view, setView ] = useState< View >( INITIAL_VIEW );

	if ( entries.length === 0 ) {
		return (
			<div className="rrze-appointment-data-view__empty">
				<img
					alt=""
					className="rrze-appointment-data-view__empty-illustration"
					src={ relaxingIllustration }
				/>
				<p className="rrze-appointment-data-view__empty-message">
					{ __(
						'No appointment times have been set up yet.',
						'rrze-appointment'
					) }
				</p>
				<Button
					className="rrze-appointment-data-view__empty-action"
					variant="primary"
					onClick={ onAdd }
				>
					{ __( 'Add appointment times', 'rrze-appointment' ) }
				</Button>
			</div>
		);
	}

	const fields: Field< AvailabilityEntry >[] = [
		{
			id: 'date',
			label: __( 'First date', 'rrze-appointment' ),
			enableHiding: false,
			enableSorting: true,
			getValue: ( { item } ) => item.date,
			render: DateField,
		},
		{
			id: 'time',
			label: __( 'Time range', 'rrze-appointment' ),
			enableSorting: true,
			getValue: ( { item } ) => item.startTime,
			render: TimeField,
		},
		{
			id: 'slotPattern',
			label: __( 'Appointments', 'rrze-appointment' ),
			enableSorting: false,
			getValue: ( { item } ) =>
				`${ item.duration }-${ item.breakDuration }`,
			render: SlotPatternField,
		},
		{
			id: 'weekdays',
			label: __( 'Repetition', 'rrze-appointment' ),
			enableSorting: false,
			getValue: ( { item } ) => getRecurrenceLabel( item ),
			render: WeekdayLabels,
		},
	];
	const actions: Action< AvailabilityEntry >[] = [
		{
			id: 'edit',
			label: __( 'Edit', 'rrze-appointment' ),
			icon: pencil,
			isPrimary: true,
			supportsBulk: false,
			callback: ( items ) => {
				if ( items[ 0 ] ) {
					onEdit( items[ 0 ] );
				}
			},
		},
		{
			id: 'delete',
			label: __( 'Delete', 'rrze-appointment' ),
			icon: trash,
			isPrimary: true,
			supportsBulk: false,
			callback: ( items ) => {
				if ( items[ 0 ] ) {
					onDelete( items[ 0 ] );
				}
			},
		},
	];
	const filteredEntries = filterSortAndPaginate( entries, view, fields );

	return (
		<DataViews
			actions={ actions }
			data={ filteredEntries.data }
			defaultLayouts={ { table: {} } }
			fields={ fields }
			getItemId={ ( item ) => item.id }
			onChangeView={ setView }
			paginationInfo={ filteredEntries.paginationInfo }
			search={ false }
			view={ view }
		/>
	);
}
