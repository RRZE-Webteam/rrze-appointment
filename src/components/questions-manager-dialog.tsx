import {
	Button,
	Flex,
	FlexBlock,
	FlexItem,
	Modal,
	Notice,
	SelectControl,
	TextareaControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { AppointmentQuestion, AppointmentQuestionType } from '../types';
import { QuestionDataView } from './question-data-view';

interface QuestionsManagerDialogProps {
	questions: AppointmentQuestion[];
	onChange: ( questions: AppointmentQuestion[] ) => void;
	onClose: () => void;
}

interface QuestionDraft extends AppointmentQuestion {
	optionsText: string;
}

function createQuestionId(): string {
	if ( typeof crypto !== 'undefined' && crypto.randomUUID ) {
		return crypto.randomUUID();
	}

	return `question-${ Date.now() }-${ Math.random()
		.toString( 36 )
		.slice( 2 ) }`;
}

function createDraft( question?: AppointmentQuestion ): QuestionDraft {
	return {
		id: question?.id || createQuestionId(),
		label: question?.label || '',
		type: question?.type || 'text',
		required: !! question?.required,
		options: question?.options || [],
		optionsText: ( question?.options || [] ).join( '\n' ),
	};
}

export function QuestionsManagerDialog( {
	questions,
	onChange,
	onClose,
}: QuestionsManagerDialogProps ) {
	const [ draft, setDraft ] = useState< QuestionDraft | null >( null );
	const [ questionToDelete, setQuestionToDelete ] =
		useState< AppointmentQuestion | null >( null );
	const [ error, setError ] = useState( '' );

	const openEditor = ( question?: AppointmentQuestion ) => {
		setError( '' );
		setDraft( createDraft( question ) );
	};

	const saveDraft = () => {
		if ( ! draft ) {
			return;
		}

		const label = draft.label.trim();
		if ( ! label ) {
			setError( __( 'Enter a question.', 'rrze-appointment' ) );
			return;
		}

		const options = Array.from(
			new Set(
				draft.optionsText
					.split( /\r?\n/ )
					.map( ( option ) => option.trim() )
					.filter( Boolean )
			)
		);
		if ( draft.type === 'select' && options.length === 0 ) {
			setError(
				__( 'Add at least one dropdown option.', 'rrze-appointment' )
			);
			return;
		}

		const question: AppointmentQuestion = {
			id: draft.id,
			label,
			type: draft.type,
			required: draft.required,
			options: draft.type === 'select' ? options : [],
		};
		const existingIndex = questions.findIndex(
			( currentQuestion ) => currentQuestion.id === question.id
		);
		const nextQuestions = [ ...questions ];
		if ( existingIndex >= 0 ) {
			nextQuestions[ existingIndex ] = question;
		} else {
			nextQuestions.push( question );
		}

		onChange( nextQuestions );
		setDraft( null );
		setError( '' );
	};

	if ( draft ) {
		return (
			<Modal
				className="rrze-appointment-question-editor__modal"
				onRequestClose={ () => setDraft( null ) }
				size="medium"
				title={
					questions.some( ( question ) => question.id === draft.id )
						? __( 'Edit question', 'rrze-appointment' )
						: __( 'Add question', 'rrze-appointment' )
				}
			>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<TextControl
					label={ __( 'Question', 'rrze-appointment' ) }
					value={ draft.label }
					onChange={ ( label ) => {
						setDraft( { ...draft, label } );
						setError( '' );
					} }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={ __( 'Field type', 'rrze-appointment' ) }
					value={ draft.type }
					options={ [
						{
							label: __( 'Free text', 'rrze-appointment' ),
							value: 'text',
						},
						{
							label: __( 'Dropdown', 'rrze-appointment' ),
							value: 'select',
						},
					] }
					onChange={ ( type ) => {
						setDraft( {
							...draft,
							type: type as AppointmentQuestionType,
						} );
						setError( '' );
					} }
				/>
				{ draft.type === 'select' && (
					<TextareaControl
						label={ __( 'Dropdown options', 'rrze-appointment' ) }
						help={ __(
							'Enter one option per line.',
							'rrze-appointment'
						) }
						value={ draft.optionsText }
						onChange={ ( optionsText ) => {
							setDraft( { ...draft, optionsText } );
							setError( '' );
						} }
						rows={ 6 }
						__nextHasNoMarginBottom
					/>
				) }
				<ToggleControl
					label={ __( 'Mandatory field', 'rrze-appointment' ) }
					help={ __(
						'People must answer this question before requesting an appointment.',
						'rrze-appointment'
					) }
					checked={ draft.required }
					onChange={ ( required ) =>
						setDraft( { ...draft, required: !! required } )
					}
					__nextHasNoMarginBottom
				/>
				<div className="rrze-appointment-question-editor__actions">
					<Button
						variant="tertiary"
						onClick={ () => setDraft( null ) }
					>
						{ __( 'Cancel', 'rrze-appointment' ) }
					</Button>
					<Button variant="primary" onClick={ saveDraft }>
						{ __( 'Save question', 'rrze-appointment' ) }
					</Button>
				</div>
			</Modal>
		);
	}

	if ( questionToDelete ) {
		return (
			<Modal
				onRequestClose={ () => setQuestionToDelete( null ) }
				size="small"
				title={ __( 'Delete question', 'rrze-appointment' ) }
			>
				<p>
					{ sprintf(
						/* translators: %s: Question that will be deleted. */
						__(
							'Are you sure you want to delete “%s”?',
							'rrze-appointment'
						),
						questionToDelete.label
					) }
				</p>
				<div className="rrze-appointment-question-editor__actions">
					<Button
						variant="tertiary"
						onClick={ () => setQuestionToDelete( null ) }
					>
						{ __( 'Cancel', 'rrze-appointment' ) }
					</Button>
					<Button
						isDestructive
						variant="primary"
						onClick={ () => {
							onChange(
								questions.filter(
									( question ) =>
										question.id !== questionToDelete.id
								)
							);
							setQuestionToDelete( null );
						} }
					>
						{ __( 'Delete', 'rrze-appointment' ) }
					</Button>
				</div>
			</Modal>
		);
	}

	return (
		<Modal
			className="rrze-appointment-data-view__modal"
			onRequestClose={ onClose }
			size="fill"
			title={ __( 'Manage questions', 'rrze-appointment' ) }
		>
			<Flex
				align="center"
				className="rrze-appointment-data-view__header"
				gap={ 4 }
				justify="space-between"
				wrap
			>
				<FlexBlock>
					<p>
						{ __(
							'Ask for additional information with free-text fields or dropdown selections.',
							'rrze-appointment'
						) }
					</p>
				</FlexBlock>
				{ questions.length > 0 && (
					<FlexItem>
						<Button
							icon="plus-alt2"
							variant="primary"
							onClick={ () => openEditor() }
						>
							{ __( 'Add question', 'rrze-appointment' ) }
						</Button>
					</FlexItem>
				) }
			</Flex>

			<QuestionDataView
				questions={ questions }
				onAdd={ () => openEditor() }
				onDelete={ setQuestionToDelete }
				onEdit={ openEditor }
			/>
		</Modal>
	);
}
