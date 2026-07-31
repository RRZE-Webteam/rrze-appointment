import apiFetch from '@wordpress/api-fetch';
import { BlockControls, useBlockProps } from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import {
	AddSlotDialog,
	AvailabilityDialog,
	AvailabilityManagerDialog,
	DeleteAvailabilityDialog,
	EditorSidebar,
	HoursImportDialog,
	PreviewCalendar,
} from './components';
import {
	buildAvailabilityAttributes,
	createAvailabilityId,
	getAvailabilityEntries,
} from './availability';
import type {
	AvailabilityEntry,
	DateOverrides,
	EditProps,
	FaudirPerson,
	HoursOverlay,
	MailTemplateOption,
	MailTemplatePost,
	TimeSlot,
} from './types';
import {
	formatDate,
	generateTimeSlots,
	getCalendarDates,
	minutesToTime,
	parseTimeToMinutes,
} from './utils';

export default function Edit( { attributes, setAttributes }: EditProps ) {
	const {
		title,
		dateOverrides,
		location,
		description,
		personId,
		locationUrl,
		color,
		style,
		hideWeekends,
	} = attributes;

	const colorClass = color ? `is-${ color }` : '';
	const blockProps = useBlockProps( {
		className: [
			'rrze-appointment',
			style === 'dark' ? 'is-style-dark' : 'is-style-light',
			colorClass,
		]
			.filter( Boolean )
			.join( ' ' ),
	} );

	const [ mailTemplates, setMailTemplates ] = useState<
		MailTemplateOption[]
	>( [] );
	useEffect( () => {
		apiFetch< MailTemplatePost[] >( {
			path: '/wp/v2/rrze-mail-templates?per_page=100&status=publish',
		} )
			.then( ( posts ) =>
				setMailTemplates(
					posts.map( ( p ) => ( {
						value: p.id,
						label: p.title.rendered,
					} ) )
				)
			)
			.catch( () => {} );
	}, [] );

	const [ faudirResponse ] = useState(
		() =>
			window.rrze_appointment?.persons || {
				error: true,
				message: __( 'No person data available.', 'rrze-appointment' ),
				data: [],
			}
	);

	const faudirPersons =
		! faudirResponse?.error && Array.isArray( faudirResponse?.data )
			? faudirResponse.data
			: [];
	const faudirError = faudirResponse?.error ?? false;
	const faudirMessage = faudirResponse?.message || '';
	const selectedPerson =
		faudirPersons.find( ( p ) => p.id === personId ) || null;

	const [ hoursOverlay, setHoursOverlay ] = useState< HoursOverlay | null >(
		null
	);

	const applyConsultationHours = ( person: FaudirPerson ) => {
		const hours = person.consultationHours || [];
		if ( ! hours.length ) {
			return;
		}

		const today = new Date();
		const lastDate = new Date( today );
		lastDate.setDate( today.getDate() + 56 );
		const seenWeekdays = new Set< number >();
		const importedEntries = hours.reduce< AvailabilityEntry[] >(
			( entries, hour ) => {
				if ( seenWeekdays.has( hour.weekday ) ) {
					return entries;
				}
				seenWeekdays.add( hour.weekday );

				const firstDate = new Date( today );
				let daysAhead = ( hour.weekday - today.getDay() + 7 ) % 7;
				if ( daysAhead === 0 ) {
					daysAhead = 7;
				}
				firstDate.setDate( today.getDate() + daysAhead );
				entries.push( {
					id: createAvailabilityId(),
					date: formatDate( firstDate ),
					startTime: hour.from || '09:00',
					endTime: hour.to || '17:00',
					duration: attributes.duration || 30,
					breakDuration: attributes.breakDuration || 0,
					recurrence: {
						freq: 'weekly',
						until: formatDate( lastDate ),
					},
				} );
				return entries;
			},
			[]
		);
		if ( importedEntries.length === 0 ) {
			return;
		}

		setAttributes( {
			...buildAvailabilityAttributes(
				{
					...attributes,
					selectedDates: [],
					manualDates: [],
					recurrences: {},
					recurrence: {},
					dateOverrides: {},
				},
				importedEntries
			),
			useConsultationHours: true,
		} );
		setActiveDate( importedEntries[ 0 ].date );
	};

	const derivedTitle = selectedPerson
		? `${ __( 'Consultation hours of', 'rrze-appointment' ) } ${ [
				selectedPerson.honorificPrefix,
				selectedPerson.givenName,
				selectedPerson.familyName,
		  ]
				.filter( Boolean )
				.join( ' ' ) }`
		: title;

	const calendarDates = getCalendarDates( attributes );
	const slots = generateTimeSlots( attributes );
	const appointmentSlots = generateTimeSlots( attributes, {
		includeExcluded: true,
	} );
	const availabilityEntries = getAvailabilityEntries( attributes );
	const [ activeDate, setActiveDate ] = useState( calendarDates[ 0 ] || '' );
	const [ addSlotDate, setAddSlotDate ] = useState< string | null >( null );
	const [ addSlotTime, setAddSlotTime ] = useState( '' );
	const [ addSlotEndTime, setAddSlotEndTime ] = useState( '' );
	const [ addSlotError, setAddSlotError ] = useState( '' );
	const [ showCalendarPreview, setShowCalendarPreview ] = useState( false );
	const [ showAvailabilityManager, setShowAvailabilityManager ] =
		useState( false );
	const [ returnToAvailabilityManager, setReturnToAvailabilityManager ] =
		useState( false );
	const [ availabilityDraft, setAvailabilityDraft ] =
		useState< AvailabilityEntry | null >( null );
	const [ editedAvailabilityId, setEditedAvailabilityId ] = useState( '' );
	const [ availabilityToDelete, setAvailabilityToDelete ] =
		useState< AvailabilityEntry | null >( null );

	useEffect( () => {
		if ( ! activeDate || ! calendarDates.includes( activeDate ) ) {
			setActiveDate( calendarDates[ 0 ] || '' );
		}
	}, [ activeDate, calendarDates ] );

	const activeOverrides: DateOverrides =
		dateOverrides && typeof dateOverrides === 'object' ? dateOverrides : {};

	const handleAddAvailability = ( returnToManager = false ) => {
		const defaultDate = new Date();
		defaultDate.setDate( defaultDate.getDate() + 1 );
		const startTime = attributes.startTime || '09:00';
		const startMinutes = parseTimeToMinutes( startTime ) || 9 * 60;
		const duration = attributes.duration || 30;
		setReturnToAvailabilityManager( returnToManager );
		setShowAvailabilityManager( false );
		setEditedAvailabilityId( '' );
		setAvailabilityDraft( {
			id: createAvailabilityId(),
			date: formatDate( defaultDate ),
			startTime,
			endTime: minutesToTime(
				Math.min( startMinutes + duration, 23 * 60 + 45 )
			),
			duration,
			breakDuration: 0,
			recurrence: {},
		} );
	};

	const handleEditAvailability = (
		entry: AvailabilityEntry,
		returnToManager = false
	) => {
		setActiveDate( entry.date );
		setReturnToAvailabilityManager( returnToManager );
		setShowAvailabilityManager( false );
		setEditedAvailabilityId( entry.id );
		setAvailabilityDraft( {
			...entry,
			recurrence: { ...entry.recurrence },
		} );
	};

	const handleSaveAvailability = ( entry: AvailabilityEntry ) => {
		const nextEntries = availabilityEntries.filter(
			( currentEntry ) => currentEntry.id !== editedAvailabilityId
		);
		nextEntries.push( entry );
		setAttributes( buildAvailabilityAttributes( attributes, nextEntries ) );
		setActiveDate( entry.date );
		setAvailabilityDraft( null );
		setEditedAvailabilityId( '' );
		if ( returnToAvailabilityManager ) {
			setShowAvailabilityManager( true );
		}
		setReturnToAvailabilityManager( false );
	};

	const handleCancelAvailability = () => {
		setAvailabilityDraft( null );
		setEditedAvailabilityId( '' );
		if ( returnToAvailabilityManager ) {
			setShowAvailabilityManager( true );
		}
		setReturnToAvailabilityManager( false );
	};

	const handleRequestDeleteAvailability = ( entry: AvailabilityEntry ) => {
		setShowAvailabilityManager( false );
		setReturnToAvailabilityManager( true );
		setAvailabilityToDelete( entry );
	};

	const handleCloseDeleteAvailability = () => {
		setAvailabilityToDelete( null );
		if ( returnToAvailabilityManager ) {
			setShowAvailabilityManager( true );
		}
		setReturnToAvailabilityManager( false );
	};

	const handleDeleteAvailability = ( id: string ) => {
		const nextEntries = availabilityEntries.filter(
			( entry ) => entry.id !== id
		);
		const nextAttributes = buildAvailabilityAttributes(
			attributes,
			nextEntries
		);
		setAttributes( nextAttributes );
		const nextDates = nextAttributes.selectedDates || [];
		if ( activeDate && ! nextDates.includes( activeDate ) ) {
			setActiveDate( nextDates[ 0 ] || '' );
		}
	};

	const handleRemoveSlot = ( slot: TimeSlot ) => {
		if ( ! slot || ! slot.date ) {
			return;
		}
		const overridesNext = { ...activeOverrides };
		const currentOverride = overridesNext[ slot.date ] || {};
		const removedSlots = new Set(
			Array.isArray( currentOverride.removedSlots )
				? currentOverride.removedSlots
				: []
		);
		const currentExtras = Array.isArray( currentOverride.extraSlots )
			? currentOverride.extraSlots
			: [];
		let nextExtras = currentExtras;

		if ( slot.isExtra ) {
			nextExtras = currentExtras.filter( ( entry ) => {
				const entryStart = entry.includes( '|' )
					? entry.split( '|' )[ 0 ]
					: entry;
				return entryStart !== slot.startTime;
			} );
		} else {
			removedSlots.add( slot.value );
		}

		const nextOverride = { ...currentOverride };
		if ( removedSlots.size > 0 ) {
			nextOverride.removedSlots = Array.from( removedSlots );
		} else {
			delete nextOverride.removedSlots;
		}
		if ( nextExtras.length > 0 ) {
			nextOverride.extraSlots = nextExtras;
		} else {
			delete nextOverride.extraSlots;
		}

		if ( Object.keys( nextOverride ).length === 0 ) {
			delete overridesNext[ slot.date ];
		} else {
			overridesNext[ slot.date ] = nextOverride;
		}

		setAttributes( { dateOverrides: overridesNext } );
	};

	const handleToggleException = ( slot: TimeSlot ) => {
		const overridesNext = { ...activeOverrides };
		const currentOverride = overridesNext[ slot.date ] || {};
		const removedSlots = new Set(
			Array.isArray( currentOverride.removedSlots )
				? currentOverride.removedSlots
				: []
		);

		if ( slot.isExcluded ) {
			removedSlots.delete( slot.value );
		} else {
			removedSlots.add( slot.value );
		}

		const nextOverride = { ...currentOverride };
		if ( removedSlots.size > 0 ) {
			nextOverride.removedSlots = Array.from( removedSlots );
		} else {
			delete nextOverride.removedSlots;
		}

		if ( Object.keys( nextOverride ).length === 0 ) {
			delete overridesNext[ slot.date ];
		} else {
			overridesNext[ slot.date ] = nextOverride;
		}

		setAttributes( { dateOverrides: overridesNext } );
	};

	const handleOpenAddSlot = ( date: string ) => {
		setAddSlotDate( date );
		setAddSlotTime( '' );
		setAddSlotEndTime( '' );
		setAddSlotError( '' );
	};

	const handleConfirmAddSlot = () => {
		if ( ! addSlotDate || ! addSlotTime || ! addSlotEndTime ) {
			return;
		}
		const newStartMinutes = parseTimeToMinutes( addSlotTime );
		const newEndMinutes = parseTimeToMinutes( addSlotEndTime );
		if ( newStartMinutes === null || newEndMinutes === null ) {
			setAddSlotError( __( 'Invalid time.', 'rrze-appointment' ) );
			return;
		}
		if ( newEndMinutes <= newStartMinutes ) {
			setAddSlotError(
				__( 'End time must be after start time.', 'rrze-appointment' )
			);
			return;
		}
		if ( newEndMinutes > 24 * 60 ) {
			setAddSlotError(
				__( 'Time exceeds end of day.', 'rrze-appointment' )
			);
			return;
		}
		const dateSlots = slots.filter( ( s ) => s.date === addSlotDate );
		const overlaps = dateSlots.some(
			( s ) =>
				newStartMinutes < s.endMinutes && newEndMinutes > s.startMinutes
		);
		if ( overlaps ) {
			setAddSlotError(
				__( 'This time slot is already taken.', 'rrze-appointment' )
			);
			return;
		}
		const overridesNext = { ...activeOverrides };
		const currentOverride = overridesNext[ addSlotDate ] || {};
		const nextExtras = Array.isArray( currentOverride.extraSlots )
			? [ ...currentOverride.extraSlots ]
			: [];
		// Store as "HH:MM-HH:MM" to carry custom end time
		const slotKey = `${ addSlotTime }|${ addSlotEndTime }`;
		if ( ! nextExtras.includes( slotKey ) ) {
			nextExtras.push( slotKey );
		}
		overridesNext[ addSlotDate ] = {
			...currentOverride,
			extraSlots: nextExtras,
		};
		setAttributes( { dateOverrides: overridesNext } );
		setAddSlotDate( null );
		setAddSlotTime( '' );
		setAddSlotEndTime( '' );
		setAddSlotError( '' );
	};

	let locationContent: ReactNode = location;
	if ( /^https?:\/\//.test( location ) ) {
		locationContent = <a href={ location }>{ location }</a>;
	} else if ( locationUrl ) {
		locationContent = <a href={ locationUrl }>{ location }</a>;
	}

	return (
		<Fragment>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						icon="list-view"
						label={ __(
							'Manage appointment times',
							'rrze-appointment'
						) }
						isPressed={ showAvailabilityManager }
						onClick={ () => setShowAvailabilityManager( true ) }
					/>
					<ToolbarButton
						icon="calendar-alt"
						label={ __( 'Calendar preview', 'rrze-appointment' ) }
						isPressed={ showCalendarPreview }
						onClick={ () =>
							setShowCalendarPreview( ( visible ) => ! visible )
						}
					/>
				</ToolbarGroup>
			</BlockControls>

			<EditorSidebar
				attributes={ attributes }
				derivedTitle={ derivedTitle }
				faudirError={ faudirError }
				faudirMessage={ faudirMessage }
				faudirPersons={ faudirPersons }
				mailTemplates={ mailTemplates }
				onHoursFound={ setHoursOverlay }
				setAttributes={ setAttributes }
			/>

			<div { ...blockProps }>
				<div
					className={ [ 'rrze-appointment-block', colorClass ]
						.filter( Boolean )
						.join( ' ' ) }
				>
					<form className="rrze-appointment__form">
						<fieldset className="rrze-appointment__fieldset">
							<legend className="rrze-appointment__title">
								{ derivedTitle ||
									__(
										'Appointment title',
										'rrze-appointment'
									) }
							</legend>
							{ description && <p>{ description }</p> }
							{ location && (
								<p>
									<strong>
										{ __( 'Location', 'rrze-appointment' ) }
										:
									</strong>{ ' ' }
									{ locationContent }
								</p>
							) }
							{ showCalendarPreview && (
								<div className="rrze-appointment-block__calendar-preview">
									<h3>
										{ __(
											'Calendar preview',
											'rrze-appointment'
										) }
									</h3>
									<PreviewCalendar
										slots={ slots }
										selectedDates={ calendarDates }
										onRemoveSlot={ handleRemoveSlot }
										onAddSlot={ handleOpenAddSlot }
										activeDate={ activeDate }
										setActiveDate={ setActiveDate }
										hideWeekends={ !! hideWeekends }
									/>
									{ calendarDates.length > 0 &&
										slots.length === 0 && (
											<p>
												{ __(
													'No time slots available.',
													'rrze-appointment'
												) }
											</p>
										) }
								</div>
							) }
							{ showAvailabilityManager && (
								<AvailabilityManagerDialog
									entries={ availabilityEntries }
									slots={ appointmentSlots }
									onAdd={ () =>
										handleAddAvailability( true )
									}
									onClose={ () =>
										setShowAvailabilityManager( false )
									}
									onDelete={ handleRequestDeleteAvailability }
									onEdit={ ( entry ) =>
										handleEditAvailability( entry, true )
									}
									onToggleException={ handleToggleException }
								/>
							) }
							{ availabilityDraft && (
								<AvailabilityDialog
									entries={ availabilityEntries }
									entry={ availabilityDraft }
									originalId={ editedAvailabilityId }
									onSave={ handleSaveAvailability }
									onCancel={ handleCancelAvailability }
								/>
							) }
							{ availabilityToDelete && (
								<DeleteAvailabilityDialog
									entry={ availabilityToDelete }
									onConfirm={ () => {
										handleDeleteAvailability(
											availabilityToDelete.id
										);
										handleCloseDeleteAvailability();
									} }
									onCancel={ handleCloseDeleteAvailability }
								/>
							) }
							{ addSlotDate && (
								<AddSlotDialog
									date={ addSlotDate }
									startTime={ addSlotTime }
									endTime={ addSlotEndTime }
									error={ addSlotError }
									onStartTimeChange={ ( value ) => {
										setAddSlotTime( value );
										setAddSlotError( '' );
									} }
									onEndTimeChange={ ( value ) => {
										setAddSlotEndTime( value );
										setAddSlotError( '' );
									} }
									onConfirm={ handleConfirmAddSlot }
									onCancel={ () => {
										setAddSlotDate( null );
										setAddSlotError( '' );
									} }
								/>
							) }
						</fieldset>
					</form>
				</div>
			</div>

			{ hoursOverlay && (
				<HoursImportDialog
					hoursOverlay={ hoursOverlay }
					onConfirm={ () => {
						applyConsultationHours( hoursOverlay.person );
						setHoursOverlay( null );
					} }
					onCancel={ () => setHoursOverlay( null ) }
				/>
			) }
		</Fragment>
	);
}
