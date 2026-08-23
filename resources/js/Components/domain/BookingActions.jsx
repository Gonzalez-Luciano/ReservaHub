import { useState } from 'react';
import { router } from '@inertiajs/react';
import Button from '../ui/Button';
import ConfirmDialog from '../ui/ConfirmDialog';

const CONFIRMATIONS = {
    cancel:   { title: 'Cancelar la reserva', description: 'La reserva pasa a cancelada y el horario vuelve a quedar libre. No se puede deshacer.', confirmLabel: 'Cancelar la reserva' },
    'no-show': { title: 'Marcar como ausencia', description: 'Queda registrado que la persona no se presentó. No se puede deshacer.', confirmLabel: 'Marcar ausencia' },
};

export default function BookingActions({ booking, onReschedule }) {
    const [pending, setPending] = useState(null);

    const post = (action) => router.post(`/dashboard/bookings/${booking.id}/${action}`, {}, { preserveScroll: true });

    const act = (action) => (CONFIRMATIONS[action] ? setPending(action) : post(action));

    const open = ['pending', 'confirmed'].includes(booking.status);

    return (
        <>
            {booking.status === 'pending' && <Button size="sm" variant="primary" onClick={() => post('confirm')}>Confirmar</Button>}
            {booking.status === 'confirmed' && <Button size="sm" onClick={() => post('complete')}>Completar</Button>}
            {booking.status === 'confirmed' && <Button size="sm" onClick={() => act('no-show')}>Ausencia</Button>}
            {open && <Button size="sm" onClick={onReschedule}>Reprogramar</Button>}
            {open && <Button size="sm" variant="danger" onClick={() => act('cancel')}>Cancelar</Button>}

            <ConfirmDialog
                open={pending !== null}
                onCancel={() => setPending(null)}
                onConfirm={() => { post(pending); setPending(null); }}
                {...(CONFIRMATIONS[pending] ?? {})}
            />
        </>
    );
}
