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
	EditProps,
	FaudirPerson,
	HoursOverlay,
	MailTemplateOption,
} from '../types';

interface EditorSidebarProps {
	attributes: AppointmentAttributes;
	derivedTitle: string;
	faudirError: boolean;
	faudirMessage: string;
	faudirPersons: FaudirPerson[];
	mailTemplates: MailTemplateOption[];
	onHoursFound: ( overlay: HoursOverlay ) => void;
	setAttributes: EditProps[ 'setAttributes' ];
}

export function EditorSidebar( {
	attributes,
	derivedTitle,
	faudirError,
	faudirMessage,
	faudirPersons,
	mailTemplates,
	onHoursFound,
	setAttributes,
}: EditorSidebarProps ) {
	const {
		bookingCutoff,
		description,
		disableSso,
		hideWeekends,
		location,
		locationUrl,
		personEmail,
		personId,
		personName,
		requireMessage,
		tplId,
	} = attributes;
	const editorI18n = window.rrze_appointment?.editorI18n || {};

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
