import { Link, useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import Button from '../../Components/ui/Button';
import { Input, FormField } from '../../Components/ui/Field';

export default function Login() {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e) {
        e.preventDefault();
        post('/login', {
            onFinish: () => reset('password'),
        });
    }

    return (
        <AuthCard title="Iniciar sesión">
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

                <FormField id="password" label="Contraseña" error={errors.password}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                    )}
                </FormField>

                <label className="flex items-center gap-2 text-[13px] text-muted">
                    <input
                        type="checkbox"
                        checked={data.remember}
                        onChange={(e) => setData('remember', e.target.checked)}
                    />
                    Recordarme
                </label>

                <Button type="submit" variant="primary" size="lg" disabled={processing} className="w-full">
                    Ingresar
                </Button>

                <p className="flex items-center justify-between text-[13px] text-muted">
                    <Link href="/register" className="hover:text-fg">Crear cuenta</Link>
                    <Link href="/forgot-password" className="hover:text-fg">¿Olvidaste tu contraseña?</Link>
                </p>
            </form>
        </AuthCard>
    );
}
