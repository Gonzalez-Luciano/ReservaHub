import { useForm } from '@inertiajs/react';
import AuthCard from '../../Components/AuthCard';
import Button from '../../Components/ui/Button';
import { Input, FormField } from '../../Components/ui/Field';

export default function ResetPassword({ token, email }) {
    const { data, setData, post, processing, errors } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(e) {
        e.preventDefault();
        post('/reset-password');
    }

    return (
        <AuthCard title="Restablecer contraseña">
            <form onSubmit={submit} className="flex flex-col gap-5">
                <FormField id="email" label="Correo electrónico" error={errors.email}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    )}
                </FormField>

                <FormField id="password" label="Nueva contraseña" error={errors.password}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoFocus
                        />
                    )}
                </FormField>

                <FormField id="password_confirmation" label="Confirmar contraseña" error={errors.password_confirmation}>
                    {(fieldProps) => (
                        <Input
                            {...fieldProps}
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                        />
                    )}
                </FormField>

                <Button type="submit" variant="primary" size="lg" disabled={processing} className="w-full">
                    Restablecer contraseña
                </Button>
            </form>
        </AuthCard>
    );
}
