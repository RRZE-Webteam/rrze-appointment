declare module "@wordpress/api-fetch" {
  interface ApiFetchOptions {
    path: string;
  }

  function apiFetch<T>(options: ApiFetchOptions): Promise<T>;

  export default apiFetch;
}

declare module "@wordpress/plugins" {
  import type { ComponentType } from "react";

  export function registerPlugin(
    name: string,
    settings: { render: ComponentType },
  ): void;
}

declare module "@wordpress/block-editor" {
  import type { ComponentType, HTMLAttributes, ReactNode } from "react";

  interface InspectorControlsProps {
    children?: ReactNode;
  }

  interface BlockControlsProps {
    children?: ReactNode;
  }

  interface RichTextProps {
    allowedFormats?: string[];
    className?: string;
    onChange: (value: string) => void;
    placeholder?: string;
    tagName?: string;
    value: string;
  }

  export const BlockControls: ComponentType<BlockControlsProps>;
  export const InspectorControls: ComponentType<InspectorControlsProps>;
  export const RichText: ComponentType<RichTextProps>;
  export function useBlockProps(
    props?: HTMLAttributes<HTMLDivElement>,
  ): HTMLAttributes<HTMLDivElement>;
}

declare module "@wordpress/components" {
  import type { ComponentType, ReactNode } from "react";

  type IconType = string | ComponentType<{ size?: number }> | ReactNode;

  interface BaseControlProps {
    __nextHasNoMarginBottom?: boolean;
    label?: string;
    help?: ReactNode;
  }

  interface ButtonProps {
    "aria-expanded"?: boolean;
    "aria-haspopup"?: "dialog" | boolean;
    children?: ReactNode;
    className?: string;
    disabled?: boolean;
    icon?: IconType;
    isDestructive?: boolean;
    isPressed?: boolean;
    label?: string;
    variant?: "primary" | "secondary" | "tertiary" | "link";
    isSmall?: boolean;
    onClick?: () => void;
  }

  interface ButtonGroupProps {
    children?: ReactNode;
    className?: string;
  }

  interface CardProps {
    children?: ReactNode;
    className?: string;
    elevation?: number;
    isBorderless?: boolean;
    isRounded?: boolean;
    size?: "none" | "xSmall" | "small" | "medium" | "large";
  }

  interface CheckboxControlProps extends BaseControlProps {
    checked: boolean;
    disabled?: boolean;
    onChange: (value: boolean) => void;
  }

  interface DateCalendarProps {
    disabled?: (date: Date) => boolean;
    month?: Date;
    onMonthChange?: (date: Date) => void;
    onSelect?: (date: Date | undefined) => void;
    required?: boolean;
    selected?: Date;
    weekStartsOn?: 0 | 1 | 2 | 3 | 4 | 5 | 6;
  }

  interface DatePickerProps {
    currentDate: string;
    isInvalidDate?: (date: Date) => boolean;
    onChange: (value: string) => void;
    startOfWeek?: number;
  }

  interface DropdownCallbackProps {
    isOpen: boolean;
    onClose: () => void;
    onToggle: () => void;
  }

  interface DropdownProps {
    contentClassName?: string;
    popoverProps?: { placement?: string };
    renderContent: (props: DropdownCallbackProps) => ReactNode;
    renderToggle: (props: DropdownCallbackProps) => ReactNode;
  }

  interface FlexProps {
    children?: ReactNode;
    align?: string;
    className?: string;
    direction?: "row" | "column";
    expanded?: boolean;
    gap?: number;
    justify?: string;
    wrap?: boolean;
  }

  interface ModalProps {
    children?: ReactNode;
    className?: string;
    contentLabel?: string;
    isDismissible?: boolean;
    onRequestClose: () => void;
    role?: string;
    size?: "small" | "medium" | "large" | "fill";
    title?: string;
  }

  interface NoticeProps {
    children?: ReactNode;
    className?: string;
    isDismissible?: boolean;
    status?: "error" | "warning" | "success" | "info";
  }

  interface PanelBodyProps {
    children?: ReactNode;
    title?: string;
    name?: string;
    icon?: IconType;
    initialOpen?: boolean;
  }

  interface PlaceholderProps {
    children?: ReactNode;
    className?: string;
    icon?: IconType;
    instructions?: string;
    isColumnLayout?: boolean;
    label?: string;
    preview?: ReactNode;
  }

  interface SelectOption {
    label: string;
    value: string;
  }

  interface SelectControlProps extends BaseControlProps {
    value: string;
    options: SelectOption[];
    onChange: (value: string) => void;
  }

  interface TextControlProps extends BaseControlProps {
    min?: string;
    placeholder?: string;
    rows?: number;
    value: string;
    type?: string;
    step?: number;
    onChange: (value: string) => void;
  }

  interface ToggleControlProps extends BaseControlProps {
    checked: boolean;
    onChange: (value: boolean) => void;
  }

  interface ToolbarGroupProps {
    children?: ReactNode;
  }

  interface ToolbarButtonProps {
    children?: ReactNode;
    disabled?: boolean;
    icon?: IconType;
    isPressed?: boolean;
    label: string;
    onClick?: () => void;
  }

  export const Button: ComponentType<ButtonProps>;
  export const ButtonGroup: ComponentType<ButtonGroupProps>;
  export const Card: ComponentType<CardProps>;
  export const CardBody: ComponentType<CardProps>;
  export const CardFooter: ComponentType<CardProps>;
  export const CardHeader: ComponentType<CardProps>;
  export const CheckboxControl: ComponentType<CheckboxControlProps>;
  export const DateCalendar: ComponentType<DateCalendarProps> | undefined;
  export const DatePicker: ComponentType<DatePickerProps>;
  export const Dropdown: ComponentType<DropdownProps>;
  export const Flex: ComponentType<FlexProps>;
  export const FlexBlock: ComponentType<FlexProps>;
  export const FlexItem: ComponentType<FlexProps>;
  export const Modal: ComponentType<ModalProps>;
  export const Notice: ComponentType<NoticeProps>;
  export const PanelBody: ComponentType<PanelBodyProps>;
  export const Placeholder: ComponentType<PlaceholderProps>;
  export const SelectControl: ComponentType<SelectControlProps>;
  export const Spinner: ComponentType;
  export const TextControl: ComponentType<TextControlProps>;
  export const TextareaControl: ComponentType<TextControlProps>;
  export const ToggleControl: ComponentType<ToggleControlProps>;
  export const ToolbarButton: ComponentType<ToolbarButtonProps>;
  export const ToolbarGroup: ComponentType<ToolbarGroupProps>;
}
