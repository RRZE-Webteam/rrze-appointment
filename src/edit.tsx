import apiFetch from '@wordpress/api-fetch';
import { useBlockProps } from '@wordpress/block-editor';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import {
	AddSlotDialog,
	EditorSidebar,
	HoursImportDialog,
	PreviewCalendar,
} from './components';
import { renderGroupedSlotsAccordion } from './slot-accordion';
import type {
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
		hideAllAppointmentsAccordion,
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

		// weekday: 0=So,1=Mo,...,6=Sa — wir wollen die nächsten 8 Wochen ab heute
		const today = new Date();
		const dates: string[] = [];
		for ( let i = 1; i <= 56; i++ ) {
			const d = new Date( today );
			d.setDate( today.getDate() + i );
			const jsDay = d.getDay(); // 0=So,1=Mo,...
			if ( hours.some( ( h ) => h.weekday === jsDay ) ) {
				dates.push( formatDate( d ) );
			}
		}
		if ( ! dates.length ) {
			return;
		}

		// startTime/endTime aus erstem Eintrag
		const firstHour = hours[ 0 ];
		const newStart = firstHour.from || '09:00';
		const newEnd = firstHour.to || '17:00';

		setAttributes( {
			selectedDates: dates,
			startDate: dates[ 0 ],
			endDate: dates[ dates.length - 1 ],
			useEndDate: true,
			startTime: newStart,
			endTime: newEnd,
			useConsultationHours: true,
		} );
		setActiveDate( dates[ 0 ] );
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
	const [ activeDate, setActiveDate ] = useState( calendarDates[ 0 ] || '' );
	const [ addSlotDate, setAddSlotDate ] = useState< string | null >( null );
	const [ addSlotTime, setAddSlotTime ] = useState( '' );
	const [ addSlotEndTime, setAddSlotEndTime ] = useState( '' );
	const [ addSlotError, setAddSlotError ] = useState( '' );

	useEffect( () => {
		if ( ! activeDate || ! calendarDates.includes( activeDate ) ) {
			setActiveDate( calendarDates[ 0 ] || '' );
		}
	}, [ activeDate, calendarDates ] );

	const activeOverrides: DateOverrides =
		dateOverrides && typeof dateOverrides === 'object' ? dateOverrides : {};

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
			<EditorSidebar
				activeDate={ activeDate }
				attributes={ attributes }
				calendarDates={ calendarDates }
				derivedTitle={ derivedTitle }
				faudirError={ faudirError }
				faudirMessage={ faudirMessage }
				faudirPersons={ faudirPersons }
				mailTemplates={ mailTemplates }
				onHoursFound={ setHoursOverlay }
				setActiveDate={ setActiveDate }
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
							{ slots.length > 0 ? (
								<Fragment>
									<PreviewCalendar
										slots={ slots }
										onRemoveSlot={ handleRemoveSlot }
										onAddSlot={ handleOpenAddSlot }
										activeDate={ activeDate }
										setActiveDate={ setActiveDate }
										hideWeekends={ !! hideWeekends }
									/>
									{ ! hideAllAppointmentsAccordion &&
										renderGroupedSlotsAccordion(
											slots,
											'rrze_appointment_slot_preview',
											{
												onRemoveSlot: handleRemoveSlot,
												onAddSlot: handleOpenAddSlot,
											}
										) }
								</Fragment>
							) : (
								<p>
									{ __(
										'Please select at least one day in the appointment settings.',
										'rrze-appointment'
									) }
								</p>
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
