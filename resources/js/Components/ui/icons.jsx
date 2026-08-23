function Icon({ size = 16, children, ...props }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 16 16"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            {...props}
        >
            {children}
        </svg>
    );
}

export const ClockIcon = (p) => <Icon {...p}><circle cx="8" cy="8" r="5.8" /><path d="M8 4.6V8l2.4 1.5" /></Icon>;
export const CheckIcon = (p) => <Icon {...p}><path d="M3.2 8.4l3.3 3.3L12.8 5.2" /></Icon>;
export const CheckCircleIcon = (p) => <Icon {...p}><circle cx="8" cy="8" r="5.8" /><path d="M5.3 8.2l2 2 3.5-4" /></Icon>;
export const CrossIcon = (p) => <Icon {...p}><path d="M4.4 4.4l7.2 7.2M11.6 4.4l-7.2 7.2" /></Icon>;
export const SlashCircleIcon = (p) => <Icon {...p}><circle cx="8" cy="8" r="5.8" /><path d="M3.9 12.1L12.1 3.9" /></Icon>;
export const PlusIcon = (p) => <Icon {...p}><path d="M8 3.2v9.6M3.2 8h9.6" /></Icon>;
export const ChevronDownIcon = (p) => <Icon {...p}><path d="M4.4 6.4l3.6 3.6 3.6-3.6" /></Icon>;
export const ChevronLeftIcon = (p) => <Icon {...p}><path d="M9.6 4.4L6 8l3.6 3.6" /></Icon>;
export const ArrowRightIcon = (p) => <Icon {...p}><path d="M3.2 8h9.6M10.4 5.2l2.8 2.8-2.8 2.8" /></Icon>;
export const MoreIcon = (p) => <Icon {...p}><circle cx="8" cy="4.8" r="1" /><circle cx="8" cy="8" r="1" /><circle cx="8" cy="11.2" r="1" /></Icon>;
export const MailIcon = (p) => <Icon {...p}><path d="M2.4 3.2h11.2a1.2 1.2 0 011.2 1.2v7.2a1.2 1.2 0 01-1.2 1.2H2.4a1.2 1.2 0 01-1.2-1.2V4.4a1.2 1.2 0 011.2-1.2z" /><path d="M13.6 4.4L8 8.8 2.4 4.4" /></Icon>;
export const WarningIcon = (p) => <Icon {...p}><path d="M8 2.4L2 12.4h12L8 2.4zM8 5.2v3M8 10.4v.8" /></Icon>;
export const MenuIcon = (p) => <Icon {...p}><path d="M2.4 5.2h11.2M2.4 8h11.2M2.4 10.8h11.2" /></Icon>;
export const CalendarIcon = (p) => <Icon {...p}><path d="M4.8 2.4v1.6M11.2 2.4v1.6M2.4 4h11.2v8.4a1.2 1.2 0 01-1.2 1.2H3.6a1.2 1.2 0 01-1.2-1.2V4z" /></Icon>;
export const ServiceIcon = (p) => <Icon {...p}><rect x="3" y="3" width="10" height="10" rx="1.2" /><path d="M6.4 6.4h3.2M6.4 8h3.2M6.4 9.6h1.6" /></Icon>;
export const PeopleIcon = (p) => <Icon {...p}><circle cx="5.2" cy="5" r="1.6" /><path d="M2.4 10.4a1.6 1.6 0 011.6-1.6h3.2a1.6 1.6 0 011.6 1.6v2.4M10.8 5a1.6 1.6 0 100-3.2 1.6 1.6 0 000 3.2zM10.8 9.6a2 2 0 012 2" /></Icon>;
export const HolidayIcon = (p) => <Icon {...p}><path d="M8 2.4L6.4 5.2h3.2L8 2.4zM4 12.4h8a1 1 0 001-1V5.2H3v6.2a1 1 0 001 1z" /></Icon>;
export const SettingsIcon = (p) => <Icon {...p}><circle cx="8" cy="8" r="2" /><path d="M8 1.2v2.4M8 12.4v2.4M14.8 8h-2.4M3.6 8H1.2M11.8 4.2l-1.7 1.7M5.9 10.1l-1.7 1.7M4.2 4.2l1.7 1.7M10.1 10.1l1.7 1.7" /></Icon>;
export const GridIcon = (p) => <Icon {...p}><rect x="2.4" y="2.4" width="2.8" height="2.8" /><rect x="6.8" y="2.4" width="2.8" height="2.8" /><rect x="2.4" y="6.8" width="2.8" height="2.8" /><rect x="6.8" y="6.8" width="2.8" height="2.8" /></Icon>;
export const ExternalIcon = (p) => <Icon {...p}><path d="M11.2 4.8V2.4h-2.4M13.6 2.4l-7.2 7.2M11.2 13.6H2.4V4.8" /></Icon>;
