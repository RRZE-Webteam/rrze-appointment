declare module '*.scss';

declare module '*.png' {
	const source: string;
	export default source;
}

declare module '*.svg' {
	import * as React from 'react';
	const content: React.FunctionComponent< React.SVGProps< SVGSVGElement > >;
	export default content;
}
