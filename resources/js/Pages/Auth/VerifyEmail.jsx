import { Link, useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import Button from '../../Components/ui/Button';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    function resend(e) {
        e.preventDefault();
        post('/email/verification-notification');
    }

    return (
        <AuthCard title="Verificá tu correo">
            <p className="-mt-2 mb-5 text-[15px] leading-6 text-muted">
                Gracias por registrarte. Antes de continuar, confirmá tu correo electrónico haciendo clic en el
                enlace que te mandé. Si no lo recibiste, te reenvío otro con gusto.
            </p>

            {status === 'verification-link-sent' && (
                <p className="mb-5 text-[13px] font-medium text-confirmed-fg">
                    Te mandé un nuevo enlace de verificación a la dirección que usaste para registrarte.
                </p>
            )}

            <form onSubmit={resend} className="flex items-center justify-between">
                <Button type="submit" variant="primary" size="lg" disabled={processing}>
                    Reenviar correo
                </Button>
                <Link href="/logout" method="post" as="button" className="text-[14px] text-muted hover:text-fg">
                    Cerrar sesión
                </Link>
            </form>
        </AuthCard>
    );
}
