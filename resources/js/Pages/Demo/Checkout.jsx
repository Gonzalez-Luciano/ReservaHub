import PublicLayout from '../../Components/PublicLayout';

const STATUS_LABELS = {
    pending: 'Pendiente de pago',
    approved: 'Pago aprobado',
    rejected: 'Pago rechazado',
    expired: 'Pago vencido',
};

export default function Checkout({ payment }) {
    return (
        <PublicLayout>
            <div className="mx-auto max-w-lg p-8">
                <p className="mb-6 rounded border border-amber-400 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
                    ENTORNO DE DEMOSTRACIÓN — pago simulado. No ingreses datos reales de tarjeta.
                </p>

                <h1 className="mb-2 text-2xl font-bold">Pago de seña</h1>
                <p className="mb-6 text-gray-600">
                    Este checkout no cobra nada: reproduce el comportamiento de una pasarela real para
                    poder demostrar el flujo completo.
                </p>

                <dl className="mb-8 space-y-2">
                    <div className="flex justify-between">
                        <dt className="text-gray-600">Importe</dt>
                        <dd className="font-semibold">
                            {payment.amount} {payment.currency}
                        </dd>
                    </div>
                    <div className="flex justify-between">
                        <dt className="text-gray-600">Estado</dt>
                        <dd className="font-semibold">{STATUS_LABELS[payment.status] ?? payment.status}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt className="text-gray-600">Identificador</dt>
                        <dd className="font-mono text-sm">{payment.external_id}</dd>
                    </div>
                </dl>
            </div>
        </PublicLayout>
    );
}
