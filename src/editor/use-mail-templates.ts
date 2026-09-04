import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import type { MailTemplateOption, MailTemplatePost } from './types';

export function useMailTemplates(): MailTemplateOption[] {
	const [ templates, setTemplates ] = useState< MailTemplateOption[] >( [] );

	useEffect( () => {
		apiFetch< MailTemplatePost[] >( {
			path: '/wp/v2/rrze-mail-templates?per_page=100&status=publish',
		} )
			.then( ( posts ) =>
				setTemplates(
					posts.map( ( post ) => ( {
						value: post.id,
						label: post.title.rendered,
					} ) )
				)
			)
			.catch( () => setTemplates( [] ) );
	}, [] );

	return templates;
}
