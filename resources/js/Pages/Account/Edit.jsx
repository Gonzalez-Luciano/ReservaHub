import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import PublicLayout from '../../Components/PublicLayout';
import InputError from '../../Components/InputError';

const STAFF_ROLES = ['owner', 'admin', 'employee'];

export default function Edit({ user }) {
    const { auth, status } = usePage().props;
    const Layout = STAFF_ROLES.includes(auth?.user?.role) ? DashboardLayout : PublicLayout;

    const profile = useForm({ name: user.name, email: user.email });

    function submitProfile(event) {
        event.preventDefault();
        profile.patch('/account/profile', { preserveScroll: true });
    }

    const password = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function submitPassword(event) {
        event.preventDefault();
        password.put('/account/password', {
            preserveScroll: true,
            onSuccess: () => password.reset(),
        });
    }

    return (
        <Layout>
            <div className="mx-auto max-w-2xl p-8">
                <h1 className="mb-6 text-2xl font-bold">Mi cuenta</h1>

                {status && <p className="mb-4 text-sm text-green-700">{status}</p>}

                <form onSubmit={submitProfile} className="mb-10 space-y-4">
                    <h2 className="text-lg font-semibold">Perfil</h2>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="name">Nombre</label>
                        <input
                            id="name"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={profile.data.name}
                            onChange={(event) => profile.setData('name', event.target.value)}
                        />
                        <InputError message={profile.errors.name} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="email">Email</label>
                        <input
                            id="email"
                            type="email"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={profile.data.email}
                            onChange={(event) => profile.setData('email', event.target.value)}
                        />
                        <InputError message={profile.errors.email} />
                        {profile.data.email !== user.email && (
                            <p className="mt-1 text-sm text-amber-700">
                                Al cambiar el email vas a tener que verificarlo de nuevo.
                            </p>
                        )}
                    </div>

                    <button
                        type="submit"
                        disabled={profile.processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Guardar perfil
                    </button>
                </form>

                <form onSubmit={submitPassword} className="space-y-4">
                    <h2 className="text-lg font-semibold">Contraseña</h2>
                    <p className="text-sm text-gray-600">
                        Al cambiarla se cierran todas tus otras sesiones y se revocan tus tokens de API.
                    </p>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="current_password">Contraseña actual</label>
                        <input
                            id="current_password"
                            type="password"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={password.data.current_password}
                            onChange={(event) => password.setData('current_password', event.target.value)}
                        />
                        <InputError message={password.errors.current_password} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="password">Contraseña nueva</label>
                        <input
                            id="password"
                            type="password"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={password.data.password}
                            onChange={(event) => password.setData('password', event.target.value)}
                        />
                        <InputError message={password.errors.password} />
                    </div>

                    <div>
                        <label className="block text-sm font-medium" htmlFor="password_confirmation">Repetir contraseña nueva</label>
                        <input
                            id="password_confirmation"
                            type="password"
                            className="mt-1 w-full rounded-md border px-3 py-2"
                            value={password.data.password_confirmation}
                            onChange={(event) => password.setData('password_confirmation', event.target.value)}
                        />
                    </div>

                    <button
                        type="submit"
                        disabled={password.processing}
                        className="rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white"
                    >
                        Cambiar contraseña
                    </button>
                </form>
            </div>
        </Layout>
    );
}
