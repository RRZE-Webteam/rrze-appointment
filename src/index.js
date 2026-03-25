import { registerBlockType } from '@wordpress/blocks';
import './style.scss';
import Edit from './edit';
import Save from './save';

registerBlockType('rrze/appointment', {
    edit: Edit,
    save: Save,
});
