import { registerBlockType } from '@wordpress/blocks';
import './style.scss';
import './pre-publish';
import Edit from './edit';

registerBlockType('rrze/appointment', {
    edit: Edit,
    save: () => null,
});
