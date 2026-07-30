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
import { getAvailabilitySlotCount } from '../availability';
import { getRecurrenceWeekdays } from '../recurrence';
import type {
	AvailabilityEntry,
	RecurrenceFrequency,
	RecurrenceWeekday,
} from '../types';
import { formatDateWithWeekdayDisplay } from '../utils';

interface AvailabilityListProps {
	entries: AvailabilityEntry[];
	onAdd: () => void;
	onDelete: ( entry: AvailabilityEntry ) => void;
	onEdit: ( entry: AvailabilityEntry ) => void;
}

function getWeekdayLabel( weekday: RecurrenceWeekday ): string {
	switch ( weekday ) {
		case 1:
			return __( 'Monday', 'rrze-appointment' );
		case 2:
			return __( 'Tuesday', 'rrze-appointment' );
		case 3:
			return __( 'Wednesday', 'rrze-appointment' );
		case 4:
			return __( 'Thursday', 'rrze-appointment' );
		case 5:
			return __( 'Friday', 'rrze-appointment' );
		case 6:
			return __( 'Saturday', 'rrze-appointment' );
		default:
			return __( 'Sunday', 'rrze-appointment' );
	}
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
							const slotCount = getAvailabilitySlotCount( entry );
							const recurrenceWeekdays =
								entry.recurrence.freq === 'weekly'
									? getRecurrenceWeekdays(
											entry.recurrence,
											entry.date
									  )
									: [];

							return (
								<Card
									className="rrze-appointment-block__availability-card"
									key={ entry.id }
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
												{ recurrenceWeekdays.length >
													0 && (
													<p>
														{ recurrenceWeekdays
															.map(
																getWeekdayLabel
															)
															.join( ', ' ) }
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
														onDelete( entry )
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
