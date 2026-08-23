import StatusBadge from '../ui/StatusBadge';
import { ClockIcon, CheckIcon, CheckCircleIcon, CrossIcon, SlashCircleIcon } from '../ui/icons';

const MAP = {
    pending:   { tone: 'pending',   icon: ClockIcon,        label: 'Pendiente' },
    confirmed: { tone: 'confirmed', icon: CheckIcon,        label: 'Confirmada' },
    completed: { tone: 'completed', icon: CheckCircleIcon,  label: 'Completada' },
    cancelled: { tone: 'cancelled', icon: CrossIcon,        label: 'Cancelada' },
    no_show:   { tone: 'noshow',    icon: SlashCircleIcon,  label: 'Ausencia' },
};

export default function BookingStatusBadge({ status }) {
    const config = MAP[status];
    if (!config) return null;
    return <StatusBadge {...config} />;
}

export const BOOKING_SPINE = {
    pending: 'bg-pending-fg',
    confirmed: 'bg-confirmed-fg',
    completed: 'bg-completed-fg',
    cancelled: 'bg-cancelled-fg',
    no_show: 'bg-noshow-fg',
};
