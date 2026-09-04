import {
	DataViews,
	filterSortAndPaginate,
	type Action,
	type Field,
	type View,
} from '@wordpress/dataviews/wp';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { pencil, trash } from '@wordpress/icons';
import { useState } from '@wordpress/element';
import questionsIllustration from '../../../assets/images/financial-analyst-31.png';
import type { AppointmentQuestion } from '../../scheduling/types';

interface QuestionDataViewProps {
	questions: AppointmentQuestion[];
	onAdd: () => void;
	onDelete: ( question: AppointmentQuestion ) => void;
	onEdit: ( question: AppointmentQuestion ) => void;
}

const INITIAL_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 10,
	sort: {
		field: 'label',
		direction: 'asc',
	},
	titleField: 'label',
	fields: [ 'type', 'required', 'dataUse' ],
	layout: {
		density: 'comfortable',
		styles: {
			label: { minWidth: '280px' },
			type: { minWidth: '140px' },
			required: { minWidth: '120px' },
			dataUse: { minWidth: '280px' },
		},
	},
};

function getTypeLabel( question: AppointmentQuestion ): string {
	return question.type === 'select'
		? __( 'Dropdown', 'rrze-appointment' )
		: __( 'Free text', 'rrze-appointment' );
}

function getRequiredLabel( question: AppointmentQuestion ): string {
	return question.required
		? __( 'Mandatory', 'rrze-appointment' )
		: __( 'Optional', 'rrze-appointment' );
}

export function QuestionDataView( {
	questions,
	onAdd,
	onDelete,
	onEdit,
}: QuestionDataViewProps ) {
	const [ view, setView ] = useState< View >( INITIAL_VIEW );

	if ( questions.length === 0 ) {
		return (
			<div className="rrze-appointment-data-view__empty">
				<img
					alt=""
					className="rrze-appointment-data-view__empty-illustration"
					src={ questionsIllustration }
				/>
				<p className="rrze-appointment-data-view__empty-message">
					{ __(
						'No additional questions have been added yet.',
						'rrze-appointment'
					) }
				</p>
				<Button
					className="rrze-appointment-data-view__empty-action"
					variant="primary"
					onClick={ onAdd }
				>
					{ __( 'Add question', 'rrze-appointment' ) }
				</Button>
			</div>
		);
	}

	const fields: Field< AppointmentQuestion >[] = [
		{
			id: 'label',
			label: __( 'Question', 'rrze-appointment' ),
			enableHiding: false,
			enableSorting: true,
			getValue: ( { item } ) => item.label,
			render: ( { item } ) => <strong>{ item.label }</strong>,
		},
		{
			id: 'type',
			label: __( 'Field type', 'rrze-appointment' ),
			enableSorting: true,
			getValue: ( { item } ) => getTypeLabel( item ),
		},
		{
			id: 'dataUse',
			label: __( 'Purpose and data use', 'rrze-appointment' ),
			enableSorting: false,
			getValue: ( { item } ) =>
				item.dataUse ||
				__( 'Missing legal notice', 'rrze-appointment' ),
		},
		{
			id: 'required',
			label: __( 'Requirement', 'rrze-appointment' ),
			enableSorting: true,
			getValue: ( { item } ) => getRequiredLabel( item ),
			render: ( { item } ) => (
				<span
					className={ `rrze-appointment-data-view__status ${
						item.required ? 'is-required' : 'is-optional'
					}` }
				>
					{ getRequiredLabel( item ) }
				</span>
			),
		},
	];
	const actions: Action< AppointmentQuestion >[] = [
		{
			id: 'edit',
			label: __( 'Edit', 'rrze-appointment' ),
			icon: pencil,
			isPrimary: true,
			supportsBulk: false,
			callback: ( items ) => {
				if ( items[ 0 ] ) {
					onEdit( items[ 0 ] );
				}
			},
		},
		{
			id: 'delete',
			label: __( 'Delete', 'rrze-appointment' ),
			icon: trash,
			isPrimary: true,
			supportsBulk: false,
			callback: ( items ) => {
				if ( items[ 0 ] ) {
					onDelete( items[ 0 ] );
				}
			},
		},
	];
	const filteredQuestions = filterSortAndPaginate( questions, view, fields );

	return (
		<DataViews
			actions={ actions }
			data={ filteredQuestions.data }
			defaultLayouts={ { table: {} } }
			fields={ fields }
			getItemId={ ( item ) => item.id }
			onChangeView={ setView }
			paginationInfo={ filteredQuestions.paginationInfo }
			search={ false }
			view={ view }
		/>
	);
}
