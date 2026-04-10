import { registerPlugin } from '@wordpress/plugins';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const NOTICE_ID = 'rrze-appointment-missing-person-notice';

function AppointmentPublishCheck() {
    const blocks = useSelect((select) =>
        select('core/block-editor').getBlocks()
    );

    const isSavingPost = useSelect((select) =>
        select('core/editor').isSavingPost()
    );

    const isAutosaving = useSelect((select) =>
        select('core/editor').isAutosavingPost()
    );

    const postStatus = useSelect((select) =>
        select('core/editor').getCurrentPostAttribute('status')
    );

    const { editPost } = useDispatch('core/editor');
    const { createNotice, removeNotice } = useDispatch('core/notices');

    const appointmentBlocks = blocks.filter((b) => b.name === 'rrze/appointment');
    const isInvalid = appointmentBlocks.length > 0 && appointmentBlocks.some((b) => {
        const name  = b.attributes.personName?.trim();
        const email = b.attributes.personEmail?.trim();
        const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email || '');
        return !name || !email || !emailValid;
    });

    const prevSaving = useRef(false);

    useEffect(() => {
        const justSaved = prevSaving.current && !isSavingPost && !isAutosaving;
        prevSaving.current = isSavingPost && !isAutosaving;

        if (justSaved && isInvalid && postStatus === 'publish') {
            editPost({ status: 'draft' });
            createNotice(
                'error',
                __('Name and email are missing for one or more appointment blocks. The page has been saved as a draft and cannot be published until all fields are filled in.', 'rrze-appointment'),
                { id: NOTICE_ID, isDismissible: true, type: 'default' }
            );
        }

        if (!isInvalid) {
            removeNotice(NOTICE_ID);
        }
    }, [isSavingPost, isAutosaving, isInvalid, postStatus]);

    return null;
}

registerPlugin('rrze-appointment-pre-publish', {
    render: AppointmentPublishCheck,
});
