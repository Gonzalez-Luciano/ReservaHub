import { router } from '@inertiajs/react';
import PublicLayout from '../../Components/PublicLayout';

const STATUS_LABELS = {
    pending: 'Pendiente de pago',
    approved: 'Pago aprobado',
    rejected: 'Pago rechazado',
    expired: 'Pago vencido',
};

export default function Checkout({ payment, outcome_url: outcomeUrl, return_url: returnUrl }) {
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

                {payment.status === 'pending' ? (
                    <div className="space-y-3">
                        <button
                            type="button"
                            className="w-full rounded bg-emerald-600 px-4 py-2 font-semibold text-white"
                            onClick={() => router.post(outcomeUrl, { outcome: 'approved' })}
                        >
                            Simular pago aprobado
                        </button>
                        <button
                            type="button"
                            className="w-full rounded bg-red-600 px-4 py-2 font-semibold text-white"
                            onClick={() => router.post(outcomeUrl, { outcome: 'rejected' })}
                        >
                            Simular pago rechazado
                        </button>
                        <button
                            type="button"
                            className="w-full rounded border border-gray-300 px-4 py-2 font-semibold text-gray-700"
                            onClick={() => router.post(outcomeUrl, { outcome: 'abandoned' })}
                        >
                            Abandonar el pago
                        </button>
                    </div>
                ) : (
                    <a className="text-blue-600 underline" href={returnUrl}>
                        Volver a mis reservas
                    </a>
                )}
            </div>
        </PublicLayout>
    );
}
