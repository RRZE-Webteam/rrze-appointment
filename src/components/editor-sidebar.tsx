import { InspectorControls } from '@wordpress/block-editor';
import {
	Button,
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
	RecurrenceRules,
} from '../types';
import {
	buildRecurrenceAttributes,
	createRecurrenceRule,
	getRecurrenceEditorState,
} from '../recurrence';
import { formatDateDisplay } from '../utils';

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

function getRecurrenceFrequencyLabel( frequency: RecurrenceFrequency ): string {
	switch ( frequency ) {
		case 'daily':
			return __( 'Daily', 'rrze-appointment' );
		case 'weekly':
			return __( 'Weekly', 'rrze-appointment' );
		case 'monthly':
			return __( 'Monthly', 'rrze-appointment' );
		default:
			return '';
	}
}

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
		dateOverrides,
		description,
		disableSso,
		duration,
		endTime,
		hideWeekends,
		location,
		locationUrl,
		personEmail,
		personId,
		personName,
		requireMessage,
		startTime,
		tplId,
	} = attributes;
	const editorI18n = window.rrze_appointment?.editorI18n || {};
	const { manualDates, rules: recurrenceRules } =
		getRecurrenceEditorState( attributes );
	const activeRecurrence = activeDate
		? recurrenceRules[ activeDate ] || {}
		: {};
	const recurrenceFrequency = activeRecurrence.freq || '';
	const recurrenceUntil = activeRecurrence.until || '';
	const recurrenceAnchors = Object.keys( recurrenceRules ).sort();
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

	const applyRecurrence = ( settings: Recurrence ) => {
		if ( ! activeDate ) {
			return;
		}

		const nextRules: RecurrenceRules = { ...recurrenceRules };
		const nextManualDates = new Set( manualDates );
		nextManualDates.add( activeDate );

		if ( ! settings.freq ) {
			delete nextRules[ activeDate ];
		} else {
			const nextRule = createRecurrenceRule( activeDate, settings );
			if ( nextRule ) {
				nextRules[ activeDate ] = nextRule;
			}
		}

		setAttributes(
			buildRecurrenceAttributes(
				Array.from( nextManualDates ),
				nextRules
			)
		);
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

				<p className="rrze-appointment-block__active-date-hint">
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
				{ recurrenceAnchors.length > 0 && (
					<div className="rrze-appointment-block__recurrence-rules">
						<p>
							<strong>
								{ __( 'Repeat', 'rrze-appointment' ) }
							</strong>
						</p>
						{ recurrenceAnchors.map( ( anchor ) => {
							const frequency =
								recurrenceRules[ anchor ].freq || '';
							const frequencyLabel =
								getRecurrenceFrequencyLabel( frequency );

							return (
								<Button
									key={ anchor }
									variant={
										anchor === activeDate
											? 'primary'
											: 'secondary'
									}
									isSmall
									onClick={ () => setActiveDate( anchor ) }
								>
									{ formatDateDisplay( anchor ) }
									{ frequencyLabel
										? ` · ${ frequencyLabel }`
										: '' }
								</Button>
							);
						} ) }
					</div>
				) }
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
							...activeRecurrence,
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
								...activeRecurrence,
								until: value,
							} )
						}
					/>
				) }
			</PanelBody>

			<PanelBody
				title={ __( 'Calendar view', 'rrze-appointment' ) }
				name={ __( 'Calendar view', 'rrze-appointment' ) }
				icon="calendar-alt"
				initialOpen={ false }
			>
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
