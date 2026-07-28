declare module '@wordpress/api-fetch' {
	interface ApiFetchOptions {
		path: string;
	}

	function apiFetch< T >( options: ApiFetchOptions ): Promise< T >;

	export default apiFetch;
}

declare module '@wordpress/block-editor' {
	import type { ComponentType, HTMLAttributes, ReactNode } from 'react';

	interface InspectorControlsProps {
		children?: ReactNode;
	}

	export const InspectorControls: ComponentType< InspectorControlsProps >;
	export function useBlockProps(
		props?: HTMLAttributes< HTMLDivElement >
	): HTMLAttributes< HTMLDivElement >;
}

declare module '@wordpress/components' {
	import type { ComponentType, ReactNode } from 'react';

	interface BaseControlProps {
		label?: string;
		help?: ReactNode;
	}

	interface ButtonProps {
		children?: ReactNode;
		variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
		isSmall?: boolean;
		onClick?: () => void;
	}

	interface PanelBodyProps {
		children?: ReactNode;
		title?: string;
		name?: string;
		icon?: string;
		initialOpen?: boolean;
	}

	interface SelectOption {
		label: string;
		value: string;
	}

	interface SelectControlProps extends BaseControlProps {
		value: string;
		options: SelectOption[];
		onChange: ( value: string ) => void;
	}

	interface TextControlProps extends BaseControlProps {
		value: string;
		type?: string;
		step?: number;
		onChange: ( value: string ) => void;
	}

	interface ToggleControlProps extends BaseControlProps {
		checked: boolean;
		onChange: ( value: boolean ) => void;
	}

	export const Button: ComponentType< ButtonProps >;
	export const PanelBody: ComponentType< PanelBodyProps >;
	export const SelectControl: ComponentType< SelectControlProps >;
	export const TextControl: ComponentType< TextControlProps >;
	export const TextareaControl: ComponentType< TextControlProps >;
	export const ToggleControl: ComponentType< ToggleControlProps >;
}
