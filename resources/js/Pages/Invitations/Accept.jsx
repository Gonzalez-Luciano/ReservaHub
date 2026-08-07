import { useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import InputError from '../../Components/InputError';

export default function Accept({ token, email, businessName }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        name: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post(`/invitations/${token}/accept`);
    }

    return (
        <AuthCard title={`Unite a ${businessName}`}>
            <p className="mb-4 text-sm text-gray-600">Invitación para {email}</p>
            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700">Nombre</label>
                    <input
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                        autoFocus
                    />
                    <InputError message={errors.name} />
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700">Contraseña</label>
                    <input
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                    />
                    <InputError message={errors.password} />
                </div>
                <div>
                    <label className="block text-sm font-medium text-gray-700">Confirmar contraseña</label>
                    <input
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                    />
                    <InputError message={errors.password_confirmation} />
                </div>
                <button
                    type="submit"
                    disabled={processing}
                    className="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                >
                    Crear cuenta
                </button>
            </form>
        </AuthCard>
    );
}
