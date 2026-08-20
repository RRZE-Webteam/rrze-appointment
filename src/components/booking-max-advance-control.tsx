import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface BookingMaxAdvanceControlProps {
	onChange: ( minutes: number ) => void;
	value: number;
}

const MINUTES_PER_DAY = 24 * 60;
const STANDARD_VALUES = [
	0,
	3 * MINUTES_PER_DAY,
	7 * MINUTES_PER_DAY,
	14 * MINUTES_PER_DAY,
];

export function BookingMaxAdvanceControl( {
	onChange,
	value,
}: BookingMaxAdvanceControlProps ) {
	const normalizedValue = Math.max( 0, value || 0 );
	const usesCustomValue =
		normalizedValue > 0 && ! STANDARD_VALUES.includes( normalizedValue );

	return (
		<>
			<SelectControl
				label={ __( 'Earliest booking time', 'rrze-appointment' ) }
				help={ __(
					'How far in advance an appointment can first be booked.',
					'rrze-appointment'
				) }
				value={ usesCustomValue ? 'custom' : String( normalizedValue ) }
				options={ [
					{
						label: __( 'No advance limit', 'rrze-appointment' ),
						value: '0',
					},
					{
						label: __( '14 days before', 'rrze-appointment' ),
						value: String( 14 * MINUTES_PER_DAY ),
					},
					{
						label: __( '7 days before', 'rrze-appointment' ),
						value: String( 7 * MINUTES_PER_DAY ),
					},
					{
						label: __( '3 days before', 'rrze-appointment' ),
						value: String( 3 * MINUTES_PER_DAY ),
					},
					{
						label: __(
							'Custom number of days',
							'rrze-appointment'
						),
						value: 'custom',
					},
				] }
				onChange={ ( nextValue ) =>
					onChange(
						nextValue === 'custom'
							? MINUTES_PER_DAY
							: Number( nextValue )
					)
				}
			/>
			{ usesCustomValue && (
				<TextControl
					label={ __(
						'Days before appointment',
						'rrze-appointment'
					) }
					type="number"
					min="1"
					step={ 1 }
					value={ String( normalizedValue / MINUTES_PER_DAY ) }
					onChange={ ( nextValue ) => {
						const days = Number( nextValue );
						if ( Number.isInteger( days ) && days > 0 ) {
							onChange( days * MINUTES_PER_DAY );
						}
					} }
				/>
			) }
		</>
	);
}
