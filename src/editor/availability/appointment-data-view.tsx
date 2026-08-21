import {
	DataViews,
	filterSortAndPaginate,
	type Action,
	type Field,
	type View,
} from '@wordpress/dataviews/wp';
import { Button, DatePicker, Dropdown } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { calendar, notAllowed, seen, undo, unseen } from '@wordpress/icons';
import { useEffect, useMemo, useState } from '@wordpress/element';
import calendarIllustration from '../../../assets/images/calendar-31.png';
import type { TimeSlot } from '../../scheduling/types';
import {
	formatDate,
	formatDateWithWeekdayDisplay,
} from '../../scheduling/dates';

interface AppointmentDataViewProps {
	slots: TimeSlot[];
	onAdd: () => void;
	onSetDateExcluded: ( date: string, excluded: boolean ) => void;
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
	onAdd,
	onSetDateExcluded,
	onToggleException,
}: AppointmentDataViewProps ) {
	const [ view, setView ] = useState< View >( INITIAL_VIEW );
	const [ selectedDate, setSelectedDate ] = useState( '' );
	const availableDates = useMemo(
		() =>
			Array.from( new Set( slots.map( ( slot ) => slot.date ) ) ).sort(),
		[ slots ]
	);
	const availableDateSet = useMemo(
		() => new Set( availableDates ),
		[ availableDates ]
	);

	useEffect( () => {
		if ( selectedDate && ! availableDates.includes( selectedDate ) ) {
			setSelectedDate( '' );
			setView( ( currentView ) => ( { ...currentView, page: 1 } ) );
		}
	}, [ availableDates, selectedDate ] );

	if ( slots.length === 0 ) {
		return (
			<div className="rrze-appointment-data-view__empty">
				<img
					alt=""
					className="rrze-appointment-data-view__empty-illustration"
					src={ calendarIllustration }
				/>
				<p className="rrze-appointment-data-view__empty-message">
					{ __(
						'No upcoming appointments have been created yet.',
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
	const dateSlots = selectedDate
		? slots.filter( ( slot ) => slot.date === selectedDate )
		: slots;
	const allDateSlotsExcluded =
		dateSlots.length > 0 && dateSlots.every( ( slot ) => slot.isExcluded );
	const filteredSlots = filterSortAndPaginate( dateSlots, view, fields );
	const handleDateChange = ( date: string ) => {
		setSelectedDate( date );
		setView( ( currentView ) => ( { ...currentView, page: 1 } ) );
	};

	return (
		<>
			<div className="rrze-appointment-data-view__filters">
				<Dropdown
					contentClassName="rrze-appointment-data-view__date-picker-popover"
					popoverProps={ { placement: 'bottom-start' } }
					renderContent={ ( { onClose } ) => (
						<div className="rrze-appointment-data-view__date-picker-content">
							<DatePicker
								currentDate={
									selectedDate || availableDates[ 0 ]
								}
								isInvalidDate={ ( date ) =>
									! availableDateSet.has( formatDate( date ) )
								}
								onChange={ ( value ) => {
									handleDateChange( value.slice( 0, 10 ) );
									onClose();
								} }
								startOfWeek={ 1 }
							/>
						</div>
					) }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							aria-expanded={ isOpen }
							aria-haspopup="dialog"
							icon={ calendar }
							variant="secondary"
							onClick={ onToggle }
						>
							{ selectedDate
								? formatDateWithWeekdayDisplay( selectedDate )
								: __(
										'Select a date to filter by',
										'rrze-appointment'
								  ) }
						</Button>
					) }
				/>
				<div className="rrze-appointment-data-view__filter-actions">
					{ selectedDate && (
						<>
							<Button
								variant="secondary"
								onClick={ () => handleDateChange( '' ) }
							>
								{ __( 'All dates', 'rrze-appointment' ) }
							</Button>
							<Button
								icon={ allDateSlotsExcluded ? seen : unseen }
								variant="secondary"
								onClick={ () =>
									onSetDateExcluded(
										selectedDate,
										! allDateSlotsExcluded
									)
								}
							>
								{ allDateSlotsExcluded
									? __(
											'Show all appointments on this date',
											'rrze-appointment'
									  )
									: __(
											'Hide all appointments on this date',
											'rrze-appointment'
									  ) }
							</Button>
						</>
					) }
				</div>
			</div>
			<DataViews
				actions={ actions }
				data={ filteredSlots.data }
				defaultLayouts={ { table: {} } }
				fields={ fields }
				getItemId={ ( item ) => item.value }
				onChangeView={ setView }
				paginationInfo={ filteredSlots.paginationInfo }
				search={ false }
				view={ view }
			/>
		</>
	);
}
