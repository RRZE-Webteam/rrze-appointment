import { registerPlugin } from '@wordpress/plugins';
import { PluginPrePublishPanel } from '@wordpress/edit-post';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const LOCK_NAME = 'rrze-appointment-missing-person';

function AppointmentPrePublishCheck() {
    const blocks = useSelect((select) =>
        select('core/block-editor').getBlocks()
    );

    const { lockPostSaving, unlockPostSaving } = useDispatch('core/editor');

    const appointmentBlocks = blocks.filter((b) => b.name === 'rrze/appointment');
    const isInvalid = appointmentBlocks.length > 0 && appointmentBlocks.some(
        (b) => {
            const name = b.attributes.personName?.trim();
            const email = b.attributes.personEmail?.trim();
            const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email || '');
            return !name || !email || !emailValid;
        }
    );

    useEffect(() => {
        if (isInvalid) {
            lockPostSaving(LOCK_NAME);
        } else {
            unlockPostSaving(LOCK_NAME);
        }
        return () => unlockPostSaving(LOCK_NAME);
    }, [isInvalid]);

    if (!isInvalid) return null;

    return (
        <PluginPrePublishPanel
            title={__('RRZE Appointment', 'rrze-appointment')}
            initialOpen={true}
        >
            <p style={{ color: '#d63638', margin: 0 }}>
                {__('Bitte Name und E-Mail für alle Termin-Blöcke angeben, bevor die Seite veröffentlicht wird.', 'rrze-appointment')}
            </p>
        </PluginPrePublishPanel>
    );
}

registerPlugin('rrze-appointment-pre-publish', {
    render: AppointmentPrePublishCheck,
});
