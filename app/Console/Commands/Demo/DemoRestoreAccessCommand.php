<?php

namespace App\Console\Commands\Demo;

use App\Models\Business;
use App\Models\User;
use App\Support\DemoEnvironment;
use App\Support\UserAccessRevoker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Restauración diaria de las credenciales publicadas de la demo.
 *
 * El dataset completo dura una semana (demo:reset), pero las credenciales no
 * pueden quedar inutilizables siete días si un visitante usa el flujo público
 * de recuperación de contraseña sobre la cuenta compartida. Esto restaura el
 * acceso y NADA más: reservas, pagos, servicios, horarios e historial siguen
 * siendo los de la semana en curso.
 *
 * No pide --force: corre desatendido todos los días y no destruye datos
 * funcionales. Las guardas de DemoEnvironment igual se aplican.
 */
class DemoRestoreAccessCommand extends Command
{
    protected $signature = 'demo:restore-access';

    protected $description = 'Devuelve las cuentas publicadas de la demo a su estado de acceso canónico.';

    public function handle(DemoEnvironment $environment, UserAccessRevoker $revoker): int
    {
        if ($failure = $environment->guardFailure()) {
            $this->error('ABORT: '.$failure);

            return self::FAILURE;
        }

        $restored = 0;
        $missing = [];

        foreach (config('demo.accounts') as $account) {
            $user = $this->locate($account);

            if ($user === null) {
                $missing[] = $account['email'];

                continue;
            }

            $this->restore($user, $account, $revoker);
            $restored++;
        }

        foreach ($missing as $email) {
            $this->warn("No se encontró la cuenta de demo {$email}: se omite.");
        }

        $this->info("Acceso restaurado en {$restored} cuentas de demo.");

        return self::SUCCESS;
    }

    /**
     * Por email primero. Si un visitante le cambió el email a una cuenta de
     * owner, se la vuelve a encontrar por (negocio, rol): `businesses.slug`
     * no es editable, así que es una referencia estable.
     *
     * @param  array{email: string, business_slug: ?string, role: string}  $account
     */
    private function locate(array $account): ?User
    {
        $user = User::withoutGlobalScopes()->where('email', $account['email'])->first();

        if ($user !== null) {
            return $user;
        }

        if ($account['role'] !== 'owner' || $account['business_slug'] === null) {
            return null;
        }

        $business = Business::withoutGlobalScopes()
            ->where('slug', $account['business_slug'])
            ->first();

        if ($business === null) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('role', 'owner')
            ->first();
    }

    /**
     * @param  array{email: string, business_slug: ?string, role: string}  $account
     */
    private function restore(User $user, array $account, UserAccessRevoker $revoker): void
    {
        $previousEmail = $user->email;

        DB::transaction(function () use ($user, $account, $previousEmail, $revoker): void {
            $user->forceFill([
                'email' => $account['email'],
                'password' => Hash::make(config('demo.password')),
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            // Corta los tres vectores de re-autenticación de quien haya tomado
            // la cuenta: remember_token, tokens de Sanctum y filas de sessions.
            $revoker->revoke($user);

            // UserAccessRevoker no cubre los enlaces de reseteo pendientes, y
            // uno vivo en el buzón público reabriría el agujero al minuto.
            DB::table('password_reset_tokens')
                ->whereIn('email', array_values(array_unique([$account['email'], $previousEmail])))
                ->delete();
        });
    }
}
