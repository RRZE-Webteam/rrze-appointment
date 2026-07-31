import {
	DataViews,
	filterSortAndPaginate,
	type Action,
	type Field,
	type View,
} from '@wordpress/dataviews/wp';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { notAllowed, undo } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import type { TimeSlot } from '../types';
import { formatDateWithWeekdayDisplay } from '../utils';

interface AppointmentDataViewProps {
	slots: TimeSlot[];
	onToggleException: ( slot: TimeSlot ) => void;
}

const INITIAL_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 25,
	sort: {
		field: 'date',
		direction: 'asc',
	},
	titleField: 'date',
	fields: [ 'time', 'status' ],
	layout: {
		density: 'comfortable',
		styles: {
			date: { minWidth: '220px' },
			time: { minWidth: '130px' },
			status: { minWidth: '120px' },
		},
	},
};

function DateField( { item }: { item: TimeSlot } ) {
	return <strong>{ formatDateWithWeekdayDisplay( item.date ) }</strong>;
}

function TimeField( { item }: { item: TimeSlot } ) {
	return (
		<span>
			{ item.startTime }–{ item.endTime }
		</span>
	);
}

function StatusField( { item }: { item: TimeSlot } ) {
	return (
		<span
			className={ `rrze-appointment-data-view__status ${
				item.isExcluded ? 'is-excluded' : 'is-available'
			}` }
		>
			{ item.isExcluded
				? __( 'Exception', 'rrze-appointment' )
				: __( 'Bookable', 'rrze-appointment' ) }
		</span>
	);
}

export function AppointmentDataView( {
	slots,
	onToggleException,
}: AppointmentDataViewProps ) {
	const [ view, setView ] = useState< View >( INITIAL_VIEW );
	const fields: Field< TimeSlot >[] = [
		{
			id: 'date',
			label: __( 'Date', 'rrze-appointment' ),
			enableHiding: false,
			enableSorting: true,
			getValue: ( { item } ) => item.date,
			render: DateField,
		},
		{
			id: 'time',
			label: __( 'Appointment time', 'rrze-appointment' ),
			enableSorting: true,
			getValue: ( { item } ) => item.startTime,
			render: TimeField,
		},
		{
			id: 'status',
			label: __( 'Status', 'rrze-appointment' ),
			enableSorting: true,
			getValue: ( { item } ) =>
				item.isExcluded
					? __( 'Exception', 'rrze-appointment' )
					: __( 'Bookable', 'rrze-appointment' ),
			render: StatusField,
		},
	];
	const actions: Action< TimeSlot >[] = [
		{
			id: 'add-exception',
			label: __( 'Add exception', 'rrze-appointment' ),
			icon: notAllowed,
			isPrimary: true,
			isEligible: ( item ) => ! item.isExcluded,
			supportsBulk: false,
			callback: ( items ) => {
				if ( items[ 0 ] ) {
					onToggleException( items[ 0 ] );
				}
			},
		},
		{
			id: 'remove-exception',
			label: __( 'Remove exception', 'rrze-appointment' ),
			icon: undo,
			isPrimary: true,
			isEligible: ( item ) => !! item.isExcluded,
			supportsBulk: false,
			callback: ( items ) => {
				if ( items[ 0 ] ) {
					onToggleException( items[ 0 ] );
				}
			},
		},
	];
	const filteredSlots = filterSortAndPaginate( slots, view, fields );

	return (
		<DataViews
			actions={ actions }
			data={ filteredSlots.data }
			defaultLayouts={ { table: {} } }
			empty={
				<Notice status="info" isDismissible={ false }>
					{ __(
						'No upcoming appointments have been created yet.',
						'rrze-appointment'
					) }
				</Notice>
			}
			fields={ fields }
			getItemId={ ( item ) => item.value }
			onChangeView={ setView }
			paginationInfo={ filteredSlots.paginationInfo }
			search={ false }
			view={ view }
		/>
	);
}
