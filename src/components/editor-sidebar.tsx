import { InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type {
	AppointmentAttributes,
	DateOverrides,
	EditProps,
	FaudirPerson,
	HoursOverlay,
	MailTemplateOption,
	Recurrence,
	RecurrenceFrequency,
} from '../types';
import { expandRecurrence, formatDateDisplay } from '../utils';
import { CalendarMultiSelect } from './calendar-multi-select';

interface EditorSidebarProps {
	activeDate: string;
	attributes: AppointmentAttributes;
	calendarDates: string[];
	derivedTitle: string;
	faudirError: boolean;
	faudirMessage: string;
	faudirPersons: FaudirPerson[];
	mailTemplates: MailTemplateOption[];
	onHoursFound: ( overlay: HoursOverlay ) => void;
	setActiveDate: ( date: string ) => void;
	setAttributes: EditProps[ 'setAttributes' ];
}

const COLOR_OPTIONS = [
	{ label: 'fau', value: 'fau' },
	{ label: 'med', value: 'med' },
	{ label: 'nat', value: 'nat' },
	{ label: 'phil', value: 'phil' },
	{ label: 'rw', value: 'rw' },
	{ label: 'tf', value: 'tf' },
];

export function EditorSidebar( {
	activeDate,
	attributes,
	calendarDates,
	derivedTitle,
	faudirError,
	faudirMessage,
	faudirPersons,
	mailTemplates,
	onHoursFound,
	setActiveDate,
	setAttributes,
}: EditorSidebarProps ) {
	const {
		bookingCutoff,
		breakDuration,
		color,
		dateOverrides,
		description,
		disableSso,
		duration,
		endTime,
		hideAllAppointmentsAccordion,
		hideWeekends,
		location,
		locationUrl,
		personEmail,
		personId,
		personName,
		recurrence,
		requireMessage,
		startTime,
		style,
		tplId,
	} = attributes;
	const editorI18n = window.rrze_appointment?.editorI18n || {};
	const styleOptions = [
		{ label: __( 'light', 'rrze-appointment' ), value: 'light' },
		{ label: __( 'dark', 'rrze-appointment' ), value: 'dark' },
	];
	const recurrenceSettings: Recurrence =
		recurrence && typeof recurrence === 'object' ? recurrence : {};
	const recurrenceFrequency = recurrenceSettings.freq || '';
	const recurrenceUntil = recurrenceSettings.until || '';
	const recurrenceAnchor = recurrenceSettings.anchor || '';
	const recurrenceDates = Array.isArray( recurrenceSettings.dates )
		? recurrenceSettings.dates
		: [];
	const firstDate = calendarDates[ 0 ] || '';
	const activeOverrides: DateOverrides =
		dateOverrides && typeof dateOverrides === 'object' ? dateOverrides : {};
	const activeOverride = activeDate
		? activeOverrides[ activeDate ] || {}
		: {};
	const effectiveStartTime = activeOverride.startTime || startTime;
	const effectiveEndTime = activeOverride.endTime || endTime;
	const effectiveDuration =
		activeOverride.duration !== null &&
		activeOverride.duration !== undefined
			? activeOverride.duration
			: duration;
	const effectiveBreakDuration =
		activeOverride.breakDuration !== null &&
		activeOverride.breakDuration !== undefined
			? activeOverride.breakDuration
			: breakDuration;

	const applyRecurrence = ( nextRecurrence: Recurrence ) => {
		if ( ! nextRecurrence.freq || ! activeDate ) {
			const nextDates = calendarDates.filter(
				( date ) =>
					! recurrenceDates.includes( date ) ||
					date === recurrenceAnchor
			);
			setAttributes( {
				recurrence: {},
				selectedDates: nextDates,
				startDate: nextDates[ 0 ] || '',
				endDate: nextDates[ nextDates.length - 1 ] || '',
				useEndDate: nextDates.length > 1,
			} );
			return;
		}

		const anchor = nextRecurrence.anchor || activeDate;
		const expandedDates = expandRecurrence(
			{ ...nextRecurrence, anchor },
			anchor
		);
		const manualDates = calendarDates.filter(
			( date ) => ! recurrenceDates.includes( date )
		);
		const nextDates = [
			...new Set( [ ...manualDates, ...expandedDates ] ),
		].sort();
		setAttributes( {
			recurrence: {
				...nextRecurrence,
				anchor,
				dates: expandedDates,
			},
			selectedDates: nextDates,
			startDate: nextDates[ 0 ] || '',
			endDate: nextDates[ nextDates.length - 1 ] || '',
			useEndDate: nextDates.length > 1,
		} );
	};

	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Mail template', 'rrze-appointment' ) }
				initialOpen={ false }
			>
				<SelectControl
					label={ __( 'Vorlage', 'rrze-appointment' ) }
					value={ String( tplId || 0 ) }
					options={ [
						{
							label: __( '— Default —', 'rrze-appointment' ),
							value: '0',
						},
						...mailTemplates.map( ( t ) => ( {
							label: t.label,
							value: String( t.value ),
						} ) ),
					] }
					onChange={ ( v ) =>
						setAttributes( { tplId: Number( v ) } )
					}
				/>
			</PanelBody>
			<PanelBody
				title={ __( 'Person settings', 'rrze-appointment' ) }
				initialOpen={ true }
			>
				{ faudirError && (
					<p className="rrze-appointment-block__person-error">
						{ faudirMessage }
					</p>
				) }
				{ ! faudirError && faudirPersons.length > 0 && (
					<SelectControl
						label={ __( 'Person', 'rrze-appointment' ) }
						value={ String( personId || 0 ) }
						options={ [
							{
								label: __( '— none —', 'rrze-appointment' ),
								value: '0',
							},
							...[ ...faudirPersons ]
								.filter(
									( p ) =>
										p.familyName || p.givenName || p.label
								)
								.map( ( p ) => ( {
									label:
										[
											p.familyName,
											p.honorificPrefix
												? `(${ p.honorificPrefix })`
												: null,
											p.givenName
												? `${ p.givenName }`
												: null,
										]
											.filter( Boolean )
											.join( ', ' ) ||
										p.label ||
										'',
									value: String( p.id ),
								} ) )
								.sort( ( a, b ) =>
									a.label.localeCompare( b.label, 'de' )
								),
						] }
						onChange={ ( value ) => {
							const pid = Number( value );
							const person =
								faudirPersons.find( ( p ) => p.id === pid ) ||
								null;
							const newTitle = person
								? `${ __(
										'Consultation hours of',
										'rrze-appointment'
								  ) } ${ [
										person.honorificPrefix,
										person.givenName,
										person.familyName,
								  ]
										.filter( Boolean )
										.join( ' ' ) }`
								: '';
							setAttributes( {
								personId: pid,
								title: newTitle,
								personName: person
									? [
											person.honorificPrefix,
											person.givenName,
											person.familyName,
									  ]
											.filter( Boolean )
											.join( ' ' )
									: '',
								personEmail: person?.email || '',
								location: person?.location || '',
								locationUrl: person?.locationUrl || '',
								useConsultationHours: false,
							} );
							if (
								person &&
								( person.consultationHours?.length ?? 0 ) > 0
							) {
								onHoursFound( {
									person,
									type:
										person.hoursType === 'office'
											? 'office'
											: 'consultation',
								} );
							}
						} }
					/>
				) }
				<TextControl
					label={ __( 'Name', 'rrze-appointment' ) }
					value={ personName }
					onChange={ ( value ) =>
						setAttributes( { personName: value } )
					}
				/>
				<TextControl
					label={ __( 'E-Mail', 'rrze-appointment' ) }
					value={ personEmail }
					onChange={ ( value ) =>
						setAttributes( { personEmail: value } )
					}
				/>
				<TextControl
					label={ __( 'Location', 'rrze-appointment' ) }
					value={ location }
					onChange={ ( value ) =>
						setAttributes( { location: value } )
					}
				/>
				<TextControl
					label={ __( 'Map (URL)', 'rrze-appointment' ) }
					help={
						locationUrl && ! /^https?:\/\//.test( locationUrl ) ? (
							<span className="rrze-appointment-block__url-error">
								{ __(
									'Please enter a valid URL (starting with https://).',
									'rrze-appointment'
								) }
							</span>
						) : (
							<a href="https://karte.fau.de">🔗 karte.fau.de</a>
						)
					}
					value={ locationUrl }
					onChange={ ( value ) =>
						setAttributes( { locationUrl: value } )
					}
				/>
			</PanelBody>
			<PanelBody
				title={ __( 'Appointment settings', 'rrze-appointment' ) }
				initialOpen={ true }
			>
				<TextControl
					label={ __( 'Title', 'rrze-appointment' ) }
					value={ derivedTitle }
					onChange={ ( value ) =>
						setAttributes( { title: value, personId: 0 } )
					}
				/>

				<TextareaControl
					label={ __( 'Description', 'rrze-appointment' ) }
					value={ description }
					onChange={ ( value ) =>
						setAttributes( { description: value } )
					}
				/>
				<SelectControl
					label={ __( 'Booking cutoff', 'rrze-appointment' ) }
					help={ __(
						'Minimum minutes before start time that booking is still allowed.',
						'rrze-appointment'
					) }
					value={ String( bookingCutoff || 0 ) }
					options={ [
						{
							label: __( 'No restriction', 'rrze-appointment' ),
							value: '0',
						},
						{ label: '15 min', value: '15' },
						{ label: '30 min', value: '30' },
						{ label: '60 min', value: '60' },
						{ label: '90 min', value: '90' },
						{ label: '120 min', value: '120' },
						{ label: '180 min', value: '180' },
						{ label: '240 min', value: '240' },
						{ label: '360 min', value: '360' },
						{ label: '720 min', value: '720' },
						{ label: '1440 min', value: '1440' },
					] }
					onChange={ ( value ) =>
						setAttributes( { bookingCutoff: Number( value ) } )
					}
				/>
				<ToggleControl
					label={
						editorI18n.requireMessageField ||
						__( 'Require message field', 'rrze-appointment' )
					}
					help={
						editorI18n.requireMessageHelp ||
						__(
							'If enabled, users must fill in the message field during booking.',
							'rrze-appointment'
						)
					}
					checked={ !! requireMessage }
					onChange={ ( value ) =>
						setAttributes( { requireMessage: !! value } )
					}
				/>
				<ToggleControl
					label={
						editorI18n.disableSsoField ||
						__( 'Disable SSO', 'rrze-appointment' )
					}
					help={
						editorI18n.disableSsoHelp ||
						__(
							'If enabled, booking works without SSO login.',
							'rrze-appointment'
						)
					}
					checked={ !! disableSso }
					onChange={ ( value ) =>
						setAttributes( { disableSso: !! value } )
					}
				/>

				<p>
					<strong>
						{ __( 'Calendar view', 'rrze-appointment' ) }
					</strong>
				</p>
				<p>
					{ __(
						'Click a date to add or remove it.',
						'rrze-appointment'
					) }
				</p>
				<CalendarMultiSelect
					selectedDates={ calendarDates }
					activeDate={ activeDate }
					onToggleDate={ ( selectedDate ) => {
						const dateSet = new Set( calendarDates );
						const overridesNext: DateOverrides = {
							...( dateOverrides &&
							typeof dateOverrides === 'object'
								? dateOverrides
								: {} ),
						};
						const wasSelected = dateSet.has( selectedDate );
						if ( wasSelected ) {
							dateSet.delete( selectedDate );
							delete overridesNext[ selectedDate ];
						} else {
							dateSet.add( selectedDate );
						}
						const nextDates = Array.from( dateSet ).sort();
						setAttributes( {
							selectedDates: nextDates,
							startDate: nextDates[ 0 ] || '',
							endDate: nextDates[ nextDates.length - 1 ] || '',
							useEndDate: nextDates.length > 1,
							dateOverrides: overridesNext,
						} );
						if ( wasSelected ) {
							if ( activeDate === selectedDate ) {
								setActiveDate( nextDates[ 0 ] || '' );
							}
						} else {
							setActiveDate( selectedDate );
						}
					} }
				/>
				<TextControl
					label={ __( 'Start time', 'rrze-appointment' ) }
					type="time"
					step={ 300 }
					value={ effectiveStartTime }
					onChange={ ( value ) => {
						if ( activeDate && activeDate !== firstDate ) {
							setAttributes( {
								dateOverrides: {
									...activeOverrides,
									[ activeDate ]: {
										...activeOverride,
										startTime: value,
									},
								},
							} );
							return;
						}
						setAttributes( { startTime: value } );
					} }
				/>
				<TextControl
					label={ __( 'End time', 'rrze-appointment' ) }
					type="time"
					step={ 300 }
					value={ effectiveEndTime }
					onChange={ ( value ) => {
						if ( activeDate && activeDate !== firstDate ) {
							setAttributes( {
								dateOverrides: {
									...activeOverrides,
									[ activeDate ]: {
										...activeOverride,
										endTime: value,
									},
								},
							} );
							return;
						}
						setAttributes( { endTime: value } );
					} }
				/>

				<SelectControl
					label={ __( 'Duration', 'rrze-appointment' ) }
					value={ String( effectiveDuration ) }
					options={ [
						{ label: '15 Minuten', value: '15' },
						{ label: '30 Minuten', value: '30' },
						{ label: '45 Minuten', value: '45' },
						{ label: '60 Minuten', value: '60' },
						{ label: '75 Minuten', value: '75' },
						{ label: '90 Minuten', value: '90' },
						{ label: '120 Minuten', value: '120' },
					] }
					onChange={ ( value ) => {
						if ( activeDate && activeDate !== firstDate ) {
							setAttributes( {
								dateOverrides: {
									...activeOverrides,
									[ activeDate ]: {
										...activeOverride,
										duration: Number( value ),
									},
								},
							} );
							return;
						}
						setAttributes( { duration: Number( value ) } );
					} }
				/>

				<SelectControl
					label={ __( 'Break', 'rrze-appointment' ) }
					value={ String( effectiveBreakDuration ) }
					options={ [
						{ label: '0 Minuten', value: '0' },
						{ label: '5 Minuten', value: '5' },
						{ label: '10 Minuten', value: '10' },
						{ label: '15 Minuten', value: '15' },
						{ label: '20 Minuten', value: '20' },
						{ label: '25 Minuten', value: '25' },
						{ label: '30 Minuten', value: '30' },
						{ label: '35 Minuten', value: '35' },
						{ label: '40 Minuten', value: '40' },
						{ label: '45 Minuten', value: '45' },
						{ label: '50 Minuten', value: '50' },
						{ label: '55 Minuten', value: '55' },
					] }
					onChange={ ( value ) => {
						if ( activeDate && activeDate !== firstDate ) {
							setAttributes( {
								dateOverrides: {
									...activeOverrides,
									[ activeDate ]: {
										...activeOverride,
										breakDuration: Number( value ),
									},
								},
							} );
							return;
						}
						setAttributes( { breakDuration: Number( value ) } );
					} }
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'Repeat', 'rrze-appointment' ) }
				initialOpen={ false }
			>
				<p className="rrze-appointment-block__recurrence-hint">
					{ activeDate
						? `${ __(
								'Applies to',
								'rrze-appointment'
						  ) }: ${ formatDateDisplay( activeDate ) }`
						: __(
								'Please select a day first.',
								'rrze-appointment'
						  ) }
				</p>
				<SelectControl
					label={ __( 'Recurrence', 'rrze-appointment' ) }
					value={ recurrenceFrequency }
					options={ [
						{
							label: __( 'Do not repeat', 'rrze-appointment' ),
							value: '',
						},
						{
							label: __( 'Daily', 'rrze-appointment' ),
							value: 'daily',
						},
						{
							label: __( 'Weekly', 'rrze-appointment' ),
							value: 'weekly',
						},
						{
							label: __( 'Monthly', 'rrze-appointment' ),
							value: 'monthly',
						},
					] }
					onChange={ ( value ) =>
						applyRecurrence( {
							...recurrenceSettings,
							freq: value as RecurrenceFrequency,
						} )
					}
				/>

				{ recurrenceFrequency && (
					<TextControl
						label={ __( 'Ends on', 'rrze-appointment' ) }
						type="date"
						value={ recurrenceUntil }
						onChange={ ( value ) =>
							applyRecurrence( {
								...recurrenceSettings,
								until: value,
							} )
						}
					/>
				) }
			</PanelBody>

			<PanelBody
				title={ __( 'Appearance', 'rrze-appointment' ) }
				name={ __( 'Appearance', 'rrze-appointment' ) }
				icon="admin-appearance"
				initialOpen={ false }
			>
				<SelectControl
					label={ __( 'Accordion style', 'rrze-appointment' ) }
					value={ style || 'light' }
					options={ styleOptions }
					onChange={ ( value ) => setAttributes( { style: value } ) }
				/>
				<SelectControl
					label={ __( 'Color', 'rrze-appointment' ) }
					value={ color || '' }
					options={ COLOR_OPTIONS }
					onChange={ ( value ) => setAttributes( { color: value } ) }
				/>
				<ToggleControl
					label={
						editorI18n.hideAllAppointmentsField ||
						__(
							'Hide "All appointments" accordion',
							'rrze-appointment'
						)
					}
					help={
						editorI18n.hideAllAppointmentsHelp ||
						__(
							'If enabled, the grouped list under "All appointments" is hidden on the frontend.',
							'rrze-appointment'
						)
					}
					checked={ !! hideAllAppointmentsAccordion }
					onChange={ ( value ) =>
						setAttributes( {
							hideAllAppointmentsAccordion: !! value,
						} )
					}
				/>
				<ToggleControl
					label={
						editorI18n.hideWeekendsField ||
						__( 'Hide weekends', 'rrze-appointment' )
					}
					help={
						editorI18n.hideWeekendsHelp ||
						__(
							'If enabled, weekend columns are not shown in the calendar.',
							'rrze-appointment'
						)
					}
					checked={ !! hideWeekends }
					onChange={ ( value ) =>
						setAttributes( { hideWeekends: !! value } )
					}
				/>
			</PanelBody>
		</InspectorControls>
	);
}
