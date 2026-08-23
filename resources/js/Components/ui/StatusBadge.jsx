const TONES = {
    pending: 'bg-pending-bg text-pending-fg',
    confirmed: 'bg-confirmed-bg text-confirmed-fg',
    completed: 'bg-completed-bg text-completed-fg',
    cancelled: 'bg-cancelled-bg text-cancelled-fg',
    noshow: 'bg-noshow-bg text-noshow-fg',
    neutral: 'bg-cancelled-bg text-muted',
};

export default function StatusBadge({ tone, icon: Icon, label }) {
    return (
        <span className={`inline-flex items-center gap-1.5 rounded px-2 py-[3px] text-xs font-medium leading-4 ${TONES[tone]}`}>
            <Icon size={13} />
            {label}
        </span>
    );
}
