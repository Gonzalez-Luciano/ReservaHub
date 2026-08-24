import { Link, useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import Button from '../../Components/ui/Button';
import { Input, FormField } from '../../Components/ui/Field';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    function submit(e) {
        e.preventDefault();
        post('/forgot-password');
    }

    return (
        <AuthCard title="Recuperar contraseña">
            <p className="-mt-2 mb-5 text-[15px] leading-6 text-muted">
                Ingresá tu correo y te mando un enlace para restablecer tu contraseña.
            </p>

            {status === 'passwords.sent' && (
                <p className="mb-5 text-[13px] font-medium text-confirmed-fg">
                    Te envié el enlace de restablecimiento por correo.
                </p>
            )}

            <form onSubmit={submit} className="flex flex-col gap-5">
                <FormField id="email" label="Correo electrónico" error={errors.email}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            autoFocus
                        />
                    )}
                </FormField>

                <Button type="submit" variant="primary" size="lg" disabled={processing} className="w-full">
                    Enviar enlace
                </Button>

                <p className="text-center text-[14px] text-muted">
                    <Link href="/login" className="font-medium text-fg hover:text-fg">Volver a iniciar sesión</Link>
                </p>
            </form>
        </AuthCard>
    );
}
