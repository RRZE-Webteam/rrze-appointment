import { registerBlockType } from '@wordpress/blocks';

import Edit from './edit';
import metadata from './block.json';
//import './editor.scss';
import './style.scss';

import './pre-publish';

registerBlockType(
	metadata.name as any,
	{
		edit: Edit,
		save: (): any => null,
	} as any
);
