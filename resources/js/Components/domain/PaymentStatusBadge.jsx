import StatusBadge from '../ui/StatusBadge';
import { ClockIcon, CheckIcon, CrossIcon, SlashCircleIcon } from '../ui/icons';

const MAP = {
    pending:  { tone: 'pending',   icon: ClockIcon,       label: 'Seña pendiente' },
    approved: { tone: 'confirmed', icon: CheckIcon,       label: 'Seña pagada' },
    rejected: { tone: 'noshow',    icon: CrossIcon,       label: 'Seña rechazada' },
    expired:  { tone: 'cancelled', icon: SlashCircleIcon, label: 'Seña vencida' },
};

export default function PaymentStatusBadge({ status }) {
    const config = MAP[status];
    if (!config) return null;
    return <StatusBadge {...config} />;
}
