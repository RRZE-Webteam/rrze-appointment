import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexBlock,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getAvailabilitySlotCount } from '../../scheduling/availability';
import type { AvailabilityEntry } from '../../scheduling/types';

const STANDARD_DURATION_MINUTES = [ 15, 30, 45, 60, 75, 90, 120 ];
const DURATION_OPTIONS = STANDARD_DURATION_MINUTES.map( ( minutes ) => ( {
	label: `${ minutes } min`,
	value: String( minutes ),
} ) );

const BREAK_OPTIONS = Array.from( { length: 12 }, ( _, index ) => {
	const minutes = index * 5;
	return {
		label: `${ minutes } min`,
		value: String( minutes ),
	};
} );

interface AppointmentPatternFieldsProps {
	draft: AvailabilityEntry;
	useCustomDuration: boolean;
	onClearError: () => void;
	onDraftChange: ( draft: AvailabilityEntry ) => void;
	onUseCustomDurationChange: ( enabled: boolean ) => void;
	onUseSingleAppointment: () => void;
}

export function usesCustomAppointmentDuration(
	entry: AvailabilityEntry
): boolean {
	return ! STANDARD_DURATION_MINUTES.includes( entry.duration );
}

export function AppointmentPatternFields( {
	draft,
	useCustomDuration,
	onClearError,
	onDraftChange,
	onUseCustomDurationChange,
	onUseSingleAppointment,
}: AppointmentPatternFieldsProps ) {
	const updateDraft = ( changes: Partial< AvailabilityEntry > ) => {
		onDraftChange( { ...draft, ...changes } );
		onClearError();
	};

	return (
		<Card size="small">
			<CardHeader>
				<Flex>
					<FlexBlock>
						<strong>
							{ __( 'Appointment pattern', 'rrze-appointment' ) }
						</strong>
					</FlexBlock>
					<Button variant="link" onClick={ onUseSingleAppointment }>
						{ __( 'Use one appointment', 'rrze-appointment' ) }
					</Button>
				</Flex>
			</CardHeader>
			<CardBody>
				<Flex direction="column" align="stretch" gap={ 3 }>
					<Flex gap={ 4 } align="flex-start" wrap>
						<FlexBlock>
							<TextControl
								label={ __(
									'Available from',
									'rrze-appointment'
								) }
								type="time"
								step={ 900 }
								value={ draft.startTime }
								onChange={ ( startTime ) =>
									updateDraft( { startTime } )
								}
							/>
						</FlexBlock>
						<FlexBlock>
							<TextControl
								label={ __(
									'Available until',
									'rrze-appointment'
								) }
								type="time"
								step={ 900 }
								value={ draft.endTime }
								onChange={ ( endTime ) =>
									updateDraft( { endTime } )
								}
							/>
						</FlexBlock>
					</Flex>
					<Flex gap={ 4 } align="flex-start" wrap>
						<FlexBlock>
							{ useCustomDuration ? (
								<TextControl
									label={ __(
										'Appointment duration',
										'rrze-appointment'
									) }
									type="number"
									min="1"
									step={ 1 }
									value={ String( draft.duration ) }
									onChange={ ( duration ) =>
										updateDraft( {
											duration: Number( duration ),
										} )
									}
								/>
							) : (
								<SelectControl
									label={ __(
										'Appointment duration',
										'rrze-appointment'
									) }
									value={ String( draft.duration ) }
									options={ DURATION_OPTIONS }
									onChange={ ( duration ) =>
										updateDraft( {
											duration: Number( duration ),
										} )
									}
								/>
							) }
							<Button
								variant="link"
								onClick={ () => {
									if (
										useCustomDuration &&
										! STANDARD_DURATION_MINUTES.includes(
											draft.duration
										)
									) {
										updateDraft( { duration: 30 } );
									} else {
										onClearError();
									}
									onUseCustomDurationChange(
										! useCustomDuration
									);
								} }
							>
								{ useCustomDuration
									? __(
											'Use standard durations',
											'rrze-appointment'
									  )
									: __(
											'Enter a custom duration',
											'rrze-appointment'
									  ) }
							</Button>
						</FlexBlock>
						<FlexBlock>
							<SelectControl
								label={ __(
									'Break between appointments',
									'rrze-appointment'
								) }
								value={ String( draft.breakDuration ) }
								options={ BREAK_OPTIONS }
								onChange={ ( breakDuration ) =>
									updateDraft( {
										breakDuration: Number( breakDuration ),
									} )
								}
							/>
						</FlexBlock>
					</Flex>
					<Notice status="info" isDismissible={ false }>
						{ getAvailabilitySlotCount( draft ) }{ ' ' }
						{ __(
							'bookable appointments per date',
							'rrze-appointment'
						) }
					</Notice>
				</Flex>
			</CardBody>
		</Card>
	);
}
