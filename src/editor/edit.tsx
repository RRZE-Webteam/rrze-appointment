import {
	BlockControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { AddSlotDialog } from './availability/add-slot-dialog';
import { AvailabilityDialog } from './availability/availability-dialog';
import { AvailabilityManagerDialog } from './availability/availability-manager-dialog';
import { DeleteAvailabilityDialog } from './availability/delete-availability-dialog';
import { PreviewCalendar } from './availability/preview-calendar';
import { FaudirImportDialog } from './faudir/faudir-import-dialog';
import { QuestionsManagerDialog } from './questions/questions-manager-dialog';
import { EditorSidebar } from './sidebar/editor-sidebar';
import { EmptyBlockPlaceholder } from './empty-block-placeholder';
import {
	buildAvailabilityAttributes,
	createAvailabilityId,
	getAvailabilityEntries,
	setDateSlotsExcluded,
} from '../scheduling/availability';
import type {
	AvailabilityEntry,
	DateOverrides,
	TimeSlot,
} from '../scheduling/types';
import type { AppointmentEditorProps } from './types';
import { formatDate, getCalendarDates } from '../scheduling/dates';
import { generateTimeSlots } from '../scheduling/schedule';
import { minutesToTime, parseTimeToMinutes } from '../scheduling/time';
import {
	calendarMonthIcon,
	questionExchangeIcon,
	scheduleIcon,
} from './material-icons';
import { useFaudirImport } from './faudir/use-faudir-import';
import { useMailTemplates } from './use-mail-templates';

export default function AppointmentEditor( {
	attributes,
	setAttributes,
}: AppointmentEditorProps ) {
	const {
		title,
		dateOverrides,
		location,
		description,
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

	const mailTemplates = useMailTemplates();

	const calendarDates = getCalendarDates( attributes );
	const appointmentSlots = generateTimeSlots( attributes, {
		includeExcluded: true,
	} );
	const availabilityEntries = getAvailabilityEntries( attributes );
	const availabilityCount = availabilityEntries.length;
	const [ activeDate, setActiveDate ] = useState( calendarDates[ 0 ] || '' );
	const faudirImport = useFaudirImport( {
		attributes,
		availabilityEntries,
		onActiveDateChange: setActiveDate,
		setAttributes,
	} );
	const [ addSlotDate, setAddSlotDate ] = useState< string | null >( null );
	const [ addSlotStartTime, setAddSlotStartTime ] = useState( '' );
	const [ addSlotEndTime, setAddSlotEndTime ] = useState( '' );
	const [ addSlotError, setAddSlotError ] = useState( '' );
	const [ showCalendarPreview, setShowCalendarPreview ] = useState(
		availabilityCount > 0
	);
	const previousAvailabilityCount = useRef( availabilityCount );
	useEffect( () => {
		if (
			previousAvailabilityCount.current === 0 &&
			availabilityCount > 0
		) {
			setShowCalendarPreview( true );
		}
		previousAvailabilityCount.current = availabilityCount;
	}, [ availabilityCount ] );
	const [ showAvailabilityManager, setShowAvailabilityManager ] =
		useState( false );
	const [ showQuestionsManager, setShowQuestionsManager ] = useState( false );
	const [
		shouldReturnToAvailabilityManager,
		setShouldReturnToAvailabilityManager,
	] = useState( false );
	const [ availabilityDraft, setAvailabilityDraft ] =
		useState< AvailabilityEntry | null >( null );
	const [ editedAvailabilityId, setEditedAvailabilityId ] = useState( '' );
	const [ availabilityToDelete, setAvailabilityToDelete ] =
		useState< AvailabilityEntry | null >( null );
	const questions = Array.isArray( attributes.questions )
		? attributes.questions
		: [];

	useEffect( () => {
		if ( ! activeDate || ! calendarDates.includes( activeDate ) ) {
			setActiveDate( calendarDates[ 0 ] || '' );
		}
	}, [ activeDate, calendarDates ] );

	const normalizedDateOverrides: DateOverrides =
		dateOverrides && typeof dateOverrides === 'object' ? dateOverrides : {};

	const handleAddAvailability = ( returnToManager = false ) => {
		const defaultDate = new Date();
		defaultDate.setDate( defaultDate.getDate() + 1 );
		const startTime = attributes.startTime || '09:00';
		const startMinutes = parseTimeToMinutes( startTime ) || 9 * 60;
		const duration = attributes.duration || 30;
		setShouldReturnToAvailabilityManager( returnToManager );
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
		setShouldReturnToAvailabilityManager( returnToManager );
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
		if ( shouldReturnToAvailabilityManager ) {
			setShowAvailabilityManager( true );
		}
		setShouldReturnToAvailabilityManager( false );
	};

	const handleCancelAvailability = () => {
		setAvailabilityDraft( null );
		setEditedAvailabilityId( '' );
		if ( shouldReturnToAvailabilityManager ) {
			setShowAvailabilityManager( true );
		}
		setShouldReturnToAvailabilityManager( false );
	};

	const handleRequestDeleteAvailability = ( entry: AvailabilityEntry ) => {
		setShowAvailabilityManager( false );
		setShouldReturnToAvailabilityManager( true );
		setAvailabilityToDelete( entry );
	};

	const handleCloseDeleteAvailability = () => {
		setAvailabilityToDelete( null );
		if ( shouldReturnToAvailabilityManager ) {
			setShowAvailabilityManager( true );
		}
		setShouldReturnToAvailabilityManager( false );
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

	const handleToggleSlotExclusion = ( slot: TimeSlot ) => {
		const nextDateOverrides = { ...normalizedDateOverrides };
		const currentOverride = nextDateOverrides[ slot.date ] || {};
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
			delete nextDateOverrides[ slot.date ];
		} else {
			nextDateOverrides[ slot.date ] = nextOverride;
		}

		setAttributes( { dateOverrides: nextDateOverrides } );
	};

	const handleSetDateExcluded = ( date: string, excluded: boolean ) => {
		setAttributes( {
			dateOverrides: setDateSlotsExcluded(
				normalizedDateOverrides,
				appointmentSlots,
				date,
				excluded
			),
		} );
	};

	const handleOpenAddSlot = ( date: string ) => {
		setAddSlotDate( date );
		setAddSlotStartTime( '' );
		setAddSlotEndTime( '' );
		setAddSlotError( '' );
	};

	const handleConfirmAddSlot = () => {
		if ( ! addSlotDate || ! addSlotStartTime || ! addSlotEndTime ) {
			return;
		}
		const newStartMinutes = parseTimeToMinutes( addSlotStartTime );
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
		const existingDateSlots = appointmentSlots.filter(
			( existingSlot ) => existingSlot.date === addSlotDate
		);
		const hasOverlap = existingDateSlots.some(
			( existingSlot ) =>
				newStartMinutes < existingSlot.endMinutes &&
				newEndMinutes > existingSlot.startMinutes
		);
		if ( hasOverlap ) {
			setAddSlotError(
				__( 'This time slot is already taken.', 'rrze-appointment' )
			);
			return;
		}
		const nextDateOverrides = { ...normalizedDateOverrides };
		const currentOverride = nextDateOverrides[ addSlotDate ] || {};
		const nextExtraSlots = Array.isArray( currentOverride.extraSlots )
			? [ ...currentOverride.extraSlots ]
			: [];
		const serializedSlotValue = `${ addSlotStartTime }|${ addSlotEndTime }`;
		if ( ! nextExtraSlots.includes( serializedSlotValue ) ) {
			nextExtraSlots.push( serializedSlotValue );
		}
		nextDateOverrides[ addSlotDate ] = {
			...currentOverride,
			extraSlots: nextExtraSlots,
		};
		setAttributes( { dateOverrides: nextDateOverrides } );
		setAddSlotDate( null );
		setAddSlotStartTime( '' );
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
						icon={ scheduleIcon }
						label={ __(
							'Manage appointment times',
							'rrze-appointment'
						) }
						isPressed={ showAvailabilityManager }
						onClick={ () => setShowAvailabilityManager( true ) }
					/>
					<ToolbarButton
						icon={ questionExchangeIcon }
						label={ __( 'Manage questions', 'rrze-appointment' ) }
						isPressed={ showQuestionsManager }
						onClick={ () => setShowQuestionsManager( true ) }
					/>
					<ToolbarButton
						icon={ calendarMonthIcon }
						label={ __( 'Calendar preview', 'rrze-appointment' ) }
						isPressed={ showCalendarPreview }
						onClick={ () =>
							setShowCalendarPreview( ( visible ) => ! visible )
						}
					/>
				</ToolbarGroup>
			</BlockControls>

			<EditorSidebar
				appointmentDateCount={ calendarDates.length }
				attributes={ attributes }
				availabilityCount={ availabilityEntries.length }
				faudirAvailable={ faudirImport.available }
				importNotice={ faudirImport.notice }
				mailTemplates={ mailTemplates }
				onImportFromFaudir={ faudirImport.open }
				onManageAppointments={ () =>
					setShowAvailabilityManager( true )
				}
				onManageQuestions={ () => setShowQuestionsManager( true ) }
				questionCount={ questions.length }
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
							{ availabilityEntries.length === 0 ? (
								<>
									<legend className="rrze-appointment__visually-hidden">
										{ __(
											'Appointment booking setup',
											'rrze-appointment'
										) }
									</legend>
									<EmptyBlockPlaceholder
										description={ description }
										title={ title }
										onAddTimes={ () =>
											handleAddAvailability()
										}
										onDescriptionChange={ ( value ) =>
											setAttributes( {
												description: value,
											} )
										}
										onTitleChange={ ( value ) =>
											setAttributes( { title: value } )
										}
									/>
								</>
							) : (
								<>
									<RichText
										tagName="legend"
										className="rrze-appointment__title"
										value={ title }
										allowedFormats={ [] }
										placeholder={ __(
											'Add appointment title…',
											'rrze-appointment'
										) }
										onChange={ ( value ) =>
											setAttributes( { title: value } )
										}
									/>
									<RichText
										tagName="p"
										className="rrze-appointment-block__description"
										value={ description }
										allowedFormats={ [] }
										placeholder={ __(
											'Add a short description…',
											'rrze-appointment'
										) }
										onChange={ ( value ) =>
											setAttributes( {
												description: value,
											} )
										}
									/>
									{ location && (
										<p>
											<strong>
												{ __(
													'Location',
													'rrze-appointment'
												) }
												:
											</strong>{ ' ' }
											{ locationContent }
										</p>
									) }
									{ showCalendarPreview && (
										<div className="rrze-appointment-block__calendar-preview">
											<div className="rrze-appointment-block__calendar-preview-header">
												<h3>
													{ __(
														'Calendar preview',
														'rrze-appointment'
													) }
												</h3>
												<p>
													{ __(
														'Select a date to review or adjust its appointment times.',
														'rrze-appointment'
													) }
												</p>
											</div>
											<PreviewCalendar
												slots={ appointmentSlots }
												selectedDates={ calendarDates }
												onToggleSlotVisibility={
													handleToggleSlotExclusion
												}
												onAddSlot={ handleOpenAddSlot }
												activeDate={ activeDate }
												onActiveDateChange={
													setActiveDate
												}
												hideWeekends={ !! hideWeekends }
											/>
										</div>
									) }
								</>
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
									onToggleSlotExclusion={
										handleToggleSlotExclusion
									}
									onSetDateExcluded={ handleSetDateExcluded }
								/>
							) }
							{ showQuestionsManager && (
								<QuestionsManagerDialog
									questions={ questions }
									onChange={ ( nextQuestions ) =>
										setAttributes( {
											questions: nextQuestions,
										} )
									}
									onClose={ () =>
										setShowQuestionsManager( false )
									}
								/>
							) }
							{ availabilityDraft && (
								<AvailabilityDialog
									existingEntries={ availabilityEntries }
									entry={ availabilityDraft }
									originalEntryId={ editedAvailabilityId }
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
									startTime={ addSlotStartTime }
									endTime={ addSlotEndTime }
									error={ addSlotError }
									onStartTimeChange={ ( value ) => {
										setAddSlotStartTime( value );
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

			{ faudirImport.isOpen && (
				<FaudirImportDialog
					error={ faudirImport.error }
					isLoading={ faudirImport.loading }
					persons={ faudirImport.persons }
					onConfirm={ faudirImport.importPerson }
					onCancel={ () => faudirImport.setOpen( false ) }
				/>
			) }
		</Fragment>
	);
}
