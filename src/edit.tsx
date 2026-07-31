import apiFetch from '@wordpress/api-fetch';
import {
	BlockControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import {
	AddSlotDialog,
	AvailabilityDialog,
	AvailabilityManagerDialog,
	DeleteAvailabilityDialog,
	EditorSidebar,
	FaudirImportDialog,
	PreviewCalendar,
} from './components';
import {
	buildAvailabilityAttributes,
	createAvailabilityId,
	getAvailabilityEntries,
} from './availability';
import {
	createFaudirAvailabilityEntries,
	mergeFaudirAvailabilityEntries,
} from './faudir';
import type {
	AvailabilityEntry,
	DateOverrides,
	EditProps,
	FaudirImportOptions,
	FaudirPerson,
	FaudirResponse,
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

	const faudirAvailable = !! window.rrze_appointment?.faudir?.available;
	const [ faudirPersons, setFaudirPersons ] = useState< FaudirPerson[] >(
		[]
	);
	const [ faudirLoaded, setFaudirLoaded ] = useState( false );
	const [ faudirLoading, setFaudirLoading ] = useState( false );
	const [ faudirError, setFaudirError ] = useState( '' );
	const [ showFaudirImport, setShowFaudirImport ] = useState( false );
	const [ faudirImportNotice, setFaudirImportNotice ] = useState( '' );

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

	const handleOpenFaudirImport = () => {
		setShowFaudirImport( true );
		if ( faudirLoaded || faudirLoading ) {
			return;
		}

		setFaudirLoading( true );
		setFaudirError( '' );
		apiFetch< FaudirResponse >( {
			path:
				window.rrze_appointment?.faudir?.personsPath ||
				'/rrze/v2/appointment/persons',
		} )
			.then( ( response ) => {
				if ( response.error ) {
					setFaudirError(
						response.message ||
							__(
								'FAUdir data could not be loaded.',
								'rrze-appointment'
							)
					);
					return;
				}
				setFaudirLoaded( true );
				setFaudirPersons(
					Array.isArray( response.data ) ? response.data : []
				);
			} )
			.catch( () => {
				setFaudirError(
					__(
						'FAUdir data could not be loaded. Please try again.',
						'rrze-appointment'
					)
				);
			} )
			.finally( () => setFaudirLoading( false ) );
	};

	const handleFaudirImport = (
		person: FaudirPerson,
		options: FaudirImportOptions
	) => {
		const nextAttributes: Partial< EditProps[ 'attributes' ] > = {
			personId: person.id,
		};
		if ( options.importContact ) {
			nextAttributes.personName =
				[ person.honorificPrefix, person.givenName, person.familyName ]
					.filter( Boolean )
					.join( ' ' ) ||
				person.label ||
				'';
			nextAttributes.personEmail = person.email || '';
		}
		if ( options.importLocation ) {
			nextAttributes.location = person.location || '';
			nextAttributes.locationUrl = person.locationUrl || '';
		}

		let addedHours = 0;
		let skippedHours = 0;
		if ( options.importHours ) {
			const importedEntries = createFaudirAvailabilityEntries(
				person.consultationHours || [],
				{
					hoursUntil: options.hoursUntil,
					duration: attributes.duration || 30,
					breakDuration: attributes.breakDuration || 0,
				}
			);
			const mergeResult = mergeFaudirAvailabilityEntries(
				availabilityEntries,
				importedEntries
			);
			addedHours = mergeResult.addedEntries.length;
			skippedHours = mergeResult.skippedCount;

			if ( addedHours > 0 ) {
				Object.assign(
					nextAttributes,
					buildAvailabilityAttributes(
						attributes,
						mergeResult.entries
					),
					{ useConsultationHours: true }
				);
				const firstImportedEntry = mergeResult.addedEntries[ 0 ];
				if ( firstImportedEntry ) {
					setActiveDate( firstImportedEntry.date );
				}
			}
		}

		setAttributes( nextAttributes );
		let importNotice: string = __(
			'FAUdir information imported.',
			'rrze-appointment'
		);
		if ( options.importHours && skippedHours > 0 ) {
			importNotice = sprintf(
				/* translators: 1: Number of imported time ranges. 2: Number of skipped overlaps. */
				__(
					'FAUdir information imported. %1$d time ranges added; %2$d overlaps skipped.',
					'rrze-appointment'
				),
				addedHours,
				skippedHours
			);
		} else if ( options.importHours ) {
			importNotice = sprintf(
				/* translators: %d: Number of imported time ranges. */
				__(
					'FAUdir information imported. %d time ranges added.',
					'rrze-appointment'
				),
				addedHours
			);
		}
		setFaudirImportNotice( importNotice );
		setShowFaudirImport( false );
	};

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
				appointmentDateCount={ calendarDates.length }
				attributes={ attributes }
				availabilityCount={ availabilityEntries.length }
				faudirAvailable={ faudirAvailable }
				importNotice={ faudirImportNotice }
				mailTemplates={ mailTemplates }
				onImportFromFaudir={ handleOpenFaudirImport }
				onManageAppointments={ () =>
					setShowAvailabilityManager( true )
				}
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
									setAttributes( { description: value } )
								}
							/>
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
										slots={ slots }
										selectedDates={ calendarDates }
										onRemoveSlot={ handleRemoveSlot }
										onAddSlot={ handleOpenAddSlot }
										activeDate={ activeDate }
										setActiveDate={ setActiveDate }
										hideWeekends={ !! hideWeekends }
									/>
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

			{ showFaudirImport && (
				<FaudirImportDialog
					error={ faudirError }
					isLoading={ faudirLoading }
					persons={ faudirPersons }
					onConfirm={ handleFaudirImport }
					onCancel={ () => setShowFaudirImport( false ) }
				/>
			) }
		</Fragment>
	);
}
