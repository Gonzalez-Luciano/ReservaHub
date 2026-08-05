import { Link, useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import InputError from '../../Components/InputError';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <AuthCard title="Recuperar contraseña">
            <p className="mb-4 text-sm text-gray-600">
                Ingresá tu correo y te enviaremos un enlace para restablecer tu contraseña.
            </p>

            {status === 'passwords.sent' && (
                <p className="mb-4 text-sm font-medium text-green-600">
                    Te enviamos el enlace de restablecimiento por correo.
                </p>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                        Correo electrónico
                    </label>
                    <input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        autoFocus
                    />
                    <InputError message={errors.email} />
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                >
                    Enviar enlace
                </button>
                <p className="text-center text-sm text-gray-600">
                    <Link href="/login" className="underline">
                        Volver a iniciar sesión
                    </Link>
                </p>
            </form>
        </AuthCard>
    );
}
