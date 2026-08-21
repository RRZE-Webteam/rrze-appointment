import { registerPlugin } from '@wordpress/plugins';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const CONTACT_NOTICE_ID = 'rrze-appointment-missing-person-notice';
const QUESTION_NOTICE_ID = 'rrze-appointment-missing-question-purpose-notice';

interface EditorBlock {
	attributes: {
		personEmail?: string;
		personName?: string;
		questions?: Array< { dataUse?: string } >;
	};
	innerBlocks?: EditorBlock[];
	name: string;
}

interface BlockEditorStore {
	getBlocks: () => EditorBlock[];
}

interface EditorStore {
	editPost: ( attributes: { status: string } ) => void;
	getCurrentPostAttribute: ( attribute: string ) => string;
	isAutosavingPost: () => boolean;
	isSavingPost: () => boolean;
}

interface NoticesStore {
	createNotice: (
		status: string,
		message: string,
		options: { id: string; isDismissible: boolean; type: string }
	) => void;
	removeNotice: ( id: string ) => void;
}

function getAppointmentBlocks( blocks: EditorBlock[] ): EditorBlock[] {
	return blocks.flatMap( ( block ) => [
		...( block.name === 'rrze/appointment' ? [ block ] : [] ),
		...getAppointmentBlocks(
			Array.isArray( block.innerBlocks ) ? block.innerBlocks : []
		),
	] );
}

function AppointmentPublishCheck() {
	const blocks = useSelect( ( select ) =>
		( select( 'core/block-editor' ) as BlockEditorStore ).getBlocks()
	);

	const isSavingPost = useSelect( ( select ) =>
		( select( 'core/editor' ) as EditorStore ).isSavingPost()
	);

	const isAutosaving = useSelect( ( select ) =>
		( select( 'core/editor' ) as EditorStore ).isAutosavingPost()
	);

	const postStatus = useSelect( ( select ) =>
		( select( 'core/editor' ) as EditorStore ).getCurrentPostAttribute(
			'status'
		)
	);

	const { editPost } = useDispatch( 'core/editor' ) as EditorStore;
	const { createNotice, removeNotice } = useDispatch(
		'core/notices'
	) as NoticesStore;

	const appointmentBlocks = getAppointmentBlocks( blocks );
	const hasInvalidContact = appointmentBlocks.some( ( b ) => {
		const name = b.attributes.personName?.trim();
		const email = b.attributes.personEmail?.trim();
		const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email || '' );
		return ! name || ! email || ! emailValid;
	} );
	const hasInvalidQuestionPurpose = appointmentBlocks.some( ( b ) => {
		const questions = Array.isArray( b.attributes.questions )
			? b.attributes.questions
			: [];
		return questions.some( ( question ) => ! question?.dataUse?.trim() );
	} );

	const prevSaving = useRef( false );

	useEffect( () => {
		const justSaved =
			prevSaving.current && ! isSavingPost && ! isAutosaving;
		prevSaving.current = isSavingPost && ! isAutosaving;

		if (
			justSaved &&
			( hasInvalidContact || hasInvalidQuestionPurpose ) &&
			postStatus === 'publish'
		) {
			editPost( { status: 'draft' } );
			if ( hasInvalidContact ) {
				createNotice(
					'error',
					__(
						'Name and email are missing for one or more appointment blocks. The page has been saved as a draft and cannot be published until all fields are filled in.',
						'rrze-appointment'
					),
					{
						id: CONTACT_NOTICE_ID,
						isDismissible: true,
						type: 'default',
					}
				);
			}
			if ( hasInvalidQuestionPurpose ) {
				createNotice(
					'error',
					__(
						'A purpose and data-use explanation is missing for one or more additional questions. The page has been saved as a draft and cannot be published until every question includes this information.',
						'rrze-appointment'
					),
					{
						id: QUESTION_NOTICE_ID,
						isDismissible: true,
						type: 'default',
					}
				);
			}
		}

		if ( ! hasInvalidContact ) {
			removeNotice( CONTACT_NOTICE_ID );
		}
		if ( ! hasInvalidQuestionPurpose ) {
			removeNotice( QUESTION_NOTICE_ID );
		}
	}, [
		isSavingPost,
		isAutosaving,
		hasInvalidContact,
		hasInvalidQuestionPurpose,
		postStatus,
		createNotice,
		editPost,
		removeNotice,
	] );

	return null;
}

registerPlugin( 'rrze-appointment-pre-publish', {
	render: AppointmentPublishCheck,
} );
