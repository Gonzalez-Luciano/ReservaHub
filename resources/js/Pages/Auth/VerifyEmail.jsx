import { Link, useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    function resend(e) {
        e.preventDefault();
        post('/email/verification-notification');
    }

    return (
        <AuthCard title="Verificá tu correo">
            <p className="mb-4 text-sm text-gray-600">
                Gracias por registrarte. Antes de continuar, confirmá tu correo electrónico haciendo clic en el
                enlace que te enviamos. Si no lo recibiste, te enviamos otro con gusto.
            </p>

            {status === 'verification-link-sent' && (
                <p className="mb-4 text-sm font-medium text-green-600">
                    Te enviamos un nuevo enlace de verificación a la dirección que usaste para registrarte.
                </p>
            )}

            <form onSubmit={resend} className="flex items-center justify-between">
                <button
                    type="submit"
                    disabled={processing}
                    className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                >
                    Reenviar correo
                </button>
                <Link href="/logout" method="post" as="button" className="text-sm underline">
                    Cerrar sesión
                </Link>
            </form>
        </AuthCard>
    );
}
