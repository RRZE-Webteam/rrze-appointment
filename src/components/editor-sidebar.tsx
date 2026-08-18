import {InspectorControls} from '@wordpress/block-editor';
import {
  Button,
  Notice,
  PanelBody,
  SelectControl,
  TextControl,
  ToggleControl,
} from '@wordpress/components';
import {__, sprintf} from '@wordpress/i18n';
import type {
  AppointmentAttributes,
  EditProps,
  MailTemplateOption,
} from '../types';

interface EditorSidebarProps {
  appointmentDateCount: number;
  attributes: AppointmentAttributes;
  availabilityCount: number;
  faudirAvailable: boolean;
  importNotice: string;
  mailTemplates: MailTemplateOption[];
  onImportFromFaudir: () => void;
  onManageAppointments: () => void;
  onManageQuestions: () => void;
  questionCount: number;
  setAttributes: EditProps[ 'setAttributes' ];
}

export function EditorSidebar({
  appointmentDateCount,
  attributes,
  availabilityCount,
  faudirAvailable,
  importNotice,
  mailTemplates,
  onImportFromFaudir,
  onManageAppointments,
  onManageQuestions,
  questionCount,
  setAttributes,
}: EditorSidebarProps) {
  const {
    bookingCutoff,
    disableSso,
    hideWeekends,
    location,
    locationUrl,
    personEmail,
    personId,
    personName,
    tplId,
  } = attributes;
  const editorI18n = window.rrze_appointment?.editorI18n || {};
  const appointmentSummary = sprintf(
    /* translators: 1: Number of schedules. 2: Number of generated appointment dates. */
    __('Schedules: %1$d · Appointment dates: %2$d', 'rrze-appointment'),
    availabilityCount,
    appointmentDateCount
  );
  const hasValidEmail =
    !personEmail ||
    /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(personEmail.trim());
  let emailHelp;
  if (!personEmail.trim()) {
    emailHelp = __(
      'Enter the address that receives booking requests.',
      'rrze-appointment'
    );
  } else if (!hasValidEmail) {
    emailHelp = __('Enter a valid email address.', 'rrze-appointment');
  }

  return (
    <InspectorControls>
      <PanelBody>
        <p className={"rrze-appointment-pill"}>{appointmentSummary}</p>
        <Button variant="secondary" onClick={onManageAppointments} className={"rrze-appointment-spacer"}>
          {__('Manage appointment times', 'rrze-appointment')}
        </Button>
        <p className={"rrze-appointment-pill"}>
          {sprintf(
            /* translators: %d: Number of configured questions. */
            __('Questions: %d', 'rrze-appointment'),
            questionCount
          )}
        </p>
        <Button variant="secondary" onClick={onManageQuestions}>
          {__('Manage questions', 'rrze-appointment')}
        </Button>
      </PanelBody>
      <PanelBody
        title={__('Host and location', 'rrze-appointment')}
        initialOpen={false}
      >
        {faudirAvailable && (
          <>
            <div className="rrze-appointment-divider">
						<p className=" components-base-control__help rrze-appointment-gentle-notice">
							{ __(
								'Optional: Copy contact details, a location, or weekly hours from FAUdir.',
								'rrze-appointment'
							) }
						</p>
						<Button
							variant="secondary"
							onClick={ onImportFromFaudir }
						>
							{ personId > 0
								? __(
										'Re-import from FAUdir',
										'rrze-appointment'
								  )
								: __(
										'Import from FAUdir',
										'rrze-appointment'
								  ) }
						</Button>
						</div>
					</>
				) }
				{ importNotice && (
					<Notice status="success" isDismissible={ false }>
						{ importNotice }
					</Notice>
				) }
				<TextControl
					label={ __( 'Host name (required)', 'rrze-appointment' ) }
					help={
						! personName.trim()
							? __(
									'Enter the name shown to people booking an appointment.',
									'rrze-appointment'
							  )
							: undefined
					}
					value={ personName }
					onChange={ ( value ) =>
						setAttributes( { personName: value, personId: 0 } )
					}
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __(
						'Host email address (required)',
						'rrze-appointment'
					) }
					type=" email"
					help={ emailHelp }
					value={ personEmail }
					onChange={ ( value ) =>
						setAttributes( { personEmail: value, personId: 0 } )
					}
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Location', 'rrze-appointment' ) }
					help={ __(
						'Optional. For example, a room, building, or video meeting.',
						'rrze-appointment'
					) }
					value={ location }
					onChange={ ( value ) =>
						setAttributes( { location: value } )
					}
					__nextHasNoMarginBottom
				/>
				<TextControl
					label={ __( 'Location link', 'rrze-appointment' ) }
					help={
						locationUrl && ! /^https?:\/\//.test( locationUrl )
							? __(
									'Enter a complete URL starting with https://.',
									'rrze-appointment'
							  )
							: __(
									'Optional. Link to a room, map, or video meeting.',
									'rrze-appointment'
							  )
					}
					value={ locationUrl }
					onChange={ ( value ) =>
						setAttributes( { locationUrl: value } )
					}
					__nextHasNoMarginBottom
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'Booking rules', 'rrze-appointment' ) }
				initialOpen={ false }
			>
				<SelectControl
					label={ __( 'Latest booking time', 'rrze-appointment' ) }
					help={ __(
						'How long before an appointment booking closes.',
						'rrze-appointment'
					) }
					value={ String( bookingCutoff || 0 ) }
					options={ [
						{
							label: __(
								'Until the appointment starts',
								'rrze-appointment'
							),
							value: '0',
						},
						{
							label: __(
								'15 minutes before',
								'rrze-appointment'
							),
							value: '15',
						},
						{
							label: __(
								'30 minutes before',
								'rrze-appointment'
							),
							value: '30',
						},
						{
							label: __( '1 hour before', 'rrze-appointment' ),
							value: '60',
						},
						{
							label: __( '2 hours before', 'rrze-appointment' ),
							value: '120',
						},
						{
							label: __( '4 hours before', 'rrze-appointment' ),
							value: '240',
						},
						{
							label: __( '12 hours before', 'rrze-appointment' ),
							value: '720',
						},
						{
							label: __( '1 day before', 'rrze-appointment' ),
							value: '1440',
						},
					] }
					onChange={ ( value ) =>
						setAttributes( { bookingCutoff: Number( value ) } )
					}
				/>
				<ToggleControl
					label={ __(
						'Allow bookings without SSO',
						'rrze-appointment'
					) }
					help={ __(
						'People can request appointments without signing in.',
						'rrze-appointment'
					) }
					checked={ !! disableSso }
					onChange={ ( value ) =>
						setAttributes( { disableSso: !! value } )
					}
					__nextHasNoMarginBottom
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'Email notifications', 'rrze-appointment' ) }
				initialOpen={ false }
			>
				<SelectControl
					label={ __( 'Mail template', 'rrze-appointment' ) }
					help={ __(
						'Choose the messages sent for this appointment.',
						'rrze-appointment'
					) }
					value={ String( tplId || 0 ) }
					options={ [
						{
							label: __( 'Default template', 'rrze-appointment' ),
							value: '0',
						},
						...mailTemplates.map( ( template ) => ( {
							label: template.label,
							value: String( template.value ),
						} ) ),
					] }
					onChange={ ( value ) =>
						setAttributes( { tplId: Number( value ) } )
					}
				/>
			</PanelBody>

			<PanelBody
				title={ __( 'Calendar display', 'rrze-appointment' ) }
				icon=" calendar-alt"
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
							'Only show Monday through Friday in the calendar.',
							'rrze-appointment'
						)
					}
					checked={ !! hideWeekends }
					onChange={ ( value ) =>
						setAttributes( { hideWeekends: !! value } )
					}
					__nextHasNoMarginBottom
				/>
			</PanelBody>
		</InspectorControls>
	);
}
