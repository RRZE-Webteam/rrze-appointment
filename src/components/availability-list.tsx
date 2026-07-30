import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	Flex,
	FlexBlock,
	FlexItem,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	getAvailabilityDates,
	getAvailabilitySlotCount,
} from '../availability';
import type { AvailabilityEntry, RecurrenceFrequency } from '../types';
import { formatDateWithWeekdayDisplay } from '../utils';

interface AvailabilityListProps {
	entries: AvailabilityEntry[];
	onAdd: () => void;
	onDelete: ( date: string ) => void;
	onEdit: ( entry: AvailabilityEntry ) => void;
}

function getRecurrenceLabel( frequency: RecurrenceFrequency ): string {
	switch ( frequency ) {
		case 'daily':
			return __( 'Daily', 'rrze-appointment' );
		case 'weekly':
			return __( 'Weekly', 'rrze-appointment' );
		case 'monthly':
			return __( 'Monthly', 'rrze-appointment' );
		default:
			return __( 'Does not repeat', 'rrze-appointment' );
	}
}

export function AvailabilityList( {
	entries,
	onAdd,
	onDelete,
	onEdit,
}: AvailabilityListProps ) {
	return (
		<Card className="rrze-appointment-block__availabilities">
			<CardHeader>
				<Flex align="flex-start" gap={ 4 } wrap>
					<FlexBlock>
						<strong>
							{ __( 'Availabilities', 'rrze-appointment' ) }
						</strong>
						<p>
							{ __(
								'Add individual appointments or optionally create consultation-hour slots from a pattern.',
								'rrze-appointment'
							) }
						</p>
					</FlexBlock>
					<FlexItem>
						<Button variant="primary" onClick={ onAdd }>
							{ __( 'Add availability', 'rrze-appointment' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				{ entries.length === 0 ? (
					<Notice status="info" isDismissible={ false }>
						{ __(
							'No availabilities have been configured yet.',
							'rrze-appointment'
						) }
					</Notice>
				) : (
					<Flex direction="column" align="stretch" gap={ 3 }>
						{ entries.map( ( entry ) => {
							const occurrenceCount =
								getAvailabilityDates( entry ).length;
							const slotCount = getAvailabilitySlotCount( entry );

							return (
								<Card
									className="rrze-appointment-block__availability-card"
									key={ entry.date }
									size="small"
								>
									<CardBody>
										<Flex align="flex-start" gap={ 4 } wrap>
											<FlexBlock>
												<strong>
													{ formatDateWithWeekdayDisplay(
														entry.date
													) }
												</strong>
												<p>
													{ entry.startTime }–
													{ entry.endTime }
												</p>
											</FlexBlock>
											<FlexItem>
												<strong>
													{ slotCount }{ ' ' }
													{ __(
														'Slots',
														'rrze-appointment'
													) }
												</strong>
												<p>
													{ entry.duration } min
													{ entry.breakDuration > 0
														? ` · ${ __(
																'Break',
																'rrze-appointment'
														  ) } ${
																entry.breakDuration
														  } min`
														: '' }
												</p>
											</FlexItem>
											<FlexItem>
												<strong>
													{ getRecurrenceLabel(
														entry.recurrence.freq ||
															''
													) }
												</strong>
												{ entry.recurrence.freq && (
													<p>
														{ occurrenceCount }{ ' ' }
														{ __(
															'Occurrences',
															'rrze-appointment'
														) }
													</p>
												) }
											</FlexItem>
										</Flex>
									</CardBody>
									<CardFooter>
										<Flex justify="flex-end">
											<FlexItem>
												<Button
													variant="secondary"
													onClick={ () =>
														onEdit( entry )
													}
												>
													{ __(
														'Edit',
														'rrze-appointment'
													) }
												</Button>
											</FlexItem>
											<FlexItem>
												<Button
													variant="tertiary"
													isDestructive
													onClick={ () =>
														onDelete( entry.date )
													}
												>
													{ __(
														'Delete',
														'rrze-appointment'
													) }
												</Button>
											</FlexItem>
										</Flex>
									</CardFooter>
								</Card>
							);
						} ) }
					</Flex>
				) }
			</CardBody>
			{ entries.length === 0 && (
				<CardFooter>
					<Button variant="secondary" onClick={ onAdd }>
						{ __( 'Add availability', 'rrze-appointment' ) }
					</Button>
				</CardFooter>
			) }
		</Card>
	);
}
