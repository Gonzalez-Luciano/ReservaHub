import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Components/DashboardLayout';
import PublicLayout from '../../Components/PublicLayout';
import PageHeader from '../../Components/ui/PageHeader';
import Button from '../../Components/ui/Button';
import Surface from '../../Components/ui/Surface';
import Alert from '../../Components/ui/Alert';
import Toast from '../../Components/ui/Toast';
import { FormField, Input } from '../../Components/ui/Field';
import { CheckCircleIcon } from '../../Components/ui/icons';

const STAFF_ROLES = ['owner', 'admin', 'employee'];

function formatVerifiedAt(value) {
    return new Date(value).toLocaleDateString('es-AR', { dateStyle: 'long' });
}

export default function Edit({ user }) {
    const { auth, status } = usePage().props;
    const isStaff = STAFF_ROLES.includes(auth?.user?.role);
    const Layout = isStaff ? DashboardLayout : PublicLayout;

    const [toastMessage, setToastMessage] = useState(
        status && status !== 'verification-link-sent' ? status : null,
    );

    useEffect(() => {
        if (status && status !== 'verification-link-sent') setToastMessage(status);
    }, [status]);

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

    const verification = useForm({});

    function resendVerification(event) {
        event.preventDefault();
        verification.post('/email/verification-notification', { preserveScroll: true });
    }

    return (
        <Layout>
            <div className={`mx-auto max-w-2xl space-y-8 ${isStaff ? '' : 'px-6 py-12 lg:px-10'}`}>
                <PageHeader title="Mi cuenta" />

                <Surface>
                    <form onSubmit={submitProfile} className="space-y-4 p-6">
                        <h2 className="text-[15px] font-semibold">Perfil</h2>

                        <FormField id="name" label="Nombre" error={profile.errors.name}>
                            {(props) => (
                                <Input
                                    {...props}
                                    value={profile.data.name}
                                    onChange={(event) => profile.setData('name', event.target.value)}
                                />
                            )}
                        </FormField>

                        <FormField id="email" label="Email" error={profile.errors.email}>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="email"
                                    value={profile.data.email}
                                    onChange={(event) => profile.setData('email', event.target.value)}
                                />
                            )}
                        </FormField>
                        {profile.data.email !== user.email && (
                            <p className="text-[13px] leading-5 text-pending-fg">
                                Al cambiar el email vas a tener que verificarlo de nuevo.
                            </p>
                        )}

                        {user.email_verified_at ? (
                            <p className="flex items-center gap-1.5 text-[13px] text-confirmed-fg">
                                <CheckCircleIcon size={14} />
                                Verificado el {formatVerifiedAt(user.email_verified_at)}.
                            </p>
                        ) : (
                            <Alert tone="warning" title="Email sin verificar">
                                <p>Confirmá tu correo para tener acceso completo a la cuenta.</p>
                                {status === 'verification-link-sent' ? (
                                    <p className="mt-2 font-medium text-confirmed-fg">
                                        Te mandamos un nuevo enlace de verificación.
                                    </p>
                                ) : (
                                    <div className="mt-3">
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            onClick={resendVerification}
                                            disabled={verification.processing}
                                        >
                                            Reenviar verificación
                                        </Button>
                                    </div>
                                )}
                            </Alert>
                        )}

                        <div className="flex justify-end pt-2">
                            <Button type="submit" variant="primary" disabled={profile.processing}>
                                Guardar perfil
                            </Button>
                        </div>
                    </form>
                </Surface>

                <Surface>
                    <form onSubmit={submitPassword} className="space-y-4 p-6">
                        <h2 className="text-[15px] font-semibold">Contraseña</h2>
                        <p className="text-[13px] leading-5 text-muted">
                            Al cambiarla se cierran todas tus otras sesiones y se revocan tus tokens de API.
                        </p>

                        <FormField id="current_password" label="Contraseña actual" error={password.errors.current_password}>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="password"
                                    value={password.data.current_password}
                                    onChange={(event) => password.setData('current_password', event.target.value)}
                                />
                            )}
                        </FormField>

                        <FormField id="password" label="Contraseña nueva" error={password.errors.password}>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="password"
                                    value={password.data.password}
                                    onChange={(event) => password.setData('password', event.target.value)}
                                />
                            )}
                        </FormField>

                        <FormField id="password_confirmation" label="Repetir contraseña nueva">
                            {(props) => (
                                <Input
                                    {...props}
                                    type="password"
                                    value={password.data.password_confirmation}
                                    onChange={(event) => password.setData('password_confirmation', event.target.value)}
                                />
                            )}
                        </FormField>

                        <div className="flex justify-end pt-2">
                            <Button type="submit" variant="primary" disabled={password.processing}>
                                Cambiar contraseña
                            </Button>
                        </div>
                    </form>
                </Surface>
            </div>

            <Toast message={toastMessage} onDismiss={() => setToastMessage(null)} />
        </Layout>
    );
}
