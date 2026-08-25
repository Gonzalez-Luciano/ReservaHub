<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Business;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * El destino de un usuario autenticado depende de su rol: el staff de un
 * negocio trabaja en /dashboard y el cliente en /mis-reservas. La landing
 * publica ('/') es solo el descarte para quien no tiene ninguno de los dos.
 *
 * Todos los puntos que dejan (o encuentran) al usuario autenticado tienen que
 * coincidir en esa respuesta, o el mismo usuario aterriza en pantallas
 * distintas segun por donde entro.
 */
class PostAuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_lands_on_the_dashboard_after_login(): void
    {
        $user = User::factory()->owner()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
    }

    public function test_employee_lands_on_the_dashboard_after_login(): void
    {
        $user = User::factory()->employee()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
    }

    public function test_customer_lands_on_their_bookings_after_login(): void
    {
        $user = User::factory()->customer()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/mis-reservas');
    }

    /**
     * El destino del staff es /dashboard porque ahi puede entrar. Con el
     * negocio desactivado, EnsureBusinessContext responde 403: mandarlo ahi
     * seria cambiar un redirect molesto por una pared.
     */
    public function test_staff_of_an_inactive_business_falls_back_to_the_landing(): void
    {
        $business = Business::factory()->create(['is_active' => false]);
        $user = User::factory()->create([
            'role' => Role::Owner,
            'business_id' => $business->id,
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
    }

    /**
     * La URL pedida antes de que el guard interrumpiera gana sobre el destino
     * por rol: es a donde el usuario queria ir.
     */
    public function test_the_intended_url_still_wins_over_the_role_destination(): void
    {
        $user = User::factory()->owner()->create();

        $this->get('/account')->assertRedirect('/login');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/account');
    }

    public function test_a_registered_business_owner_lands_on_the_dashboard(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'business',
            'name' => 'Ana Ruiz',
            'email' => 'ana@example.test',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
            'business_name' => 'Estudio Ruiz',
        ]);

        $response->assertRedirect('/dashboard');
    }

    public function test_a_registered_customer_lands_on_their_bookings(): void
    {
        $response = $this->post('/register', [
            'account_type' => 'customer',
            'name' => 'Beto Diaz',
            'email' => 'beto@example.test',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
        ]);

        $response->assertRedirect('/mis-reservas');
    }

    /**
     * El middleware `guest` mandaba a /dashboard por descarte del framework:
     * RedirectIfAuthenticated::defaultRedirectUri() elige la ruta llamada
     * "dashboard" si existe. Para un cliente eso no era un redirect molesto
     * sino un 403 de EnsureBusinessContext, y el link "Ingresar" del pie de
     * PublicLayout se muestra tambien a quien ya inicio sesion.
     */
    public function test_a_logged_in_customer_visiting_login_goes_to_their_bookings(): void
    {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/mis-reservas');
    }

    public function test_a_logged_in_customer_visiting_register_goes_to_their_bookings(): void
    {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)->get('/register')->assertRedirect('/mis-reservas');
    }

    public function test_a_logged_in_customer_visiting_forgot_password_goes_to_their_bookings(): void
    {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)->get('/forgot-password')->assertRedirect('/mis-reservas');
    }

    public function test_a_logged_in_owner_visiting_login_goes_to_the_dashboard(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/dashboard');
    }

    /**
     * Quien ya verifico y vuelve a abrir la pantalla de verificacion no tiene
     * nada que hacer ahi: se lo devuelve a su lugar de trabajo.
     */
    public function test_an_already_verified_customer_leaves_the_verification_prompt_for_their_bookings(): void
    {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)->get('/verify-email')->assertRedirect('/mis-reservas');
    }

    public function test_an_already_verified_owner_leaves_the_verification_prompt_for_the_dashboard(): void
    {
        $user = User::factory()->owner()->create();

        $this->actingAs($user)->get('/verify-email')->assertRedirect('/dashboard');
    }

    public function test_an_already_verified_customer_resending_the_link_goes_to_their_bookings(): void
    {
        $user = User::factory()->customer()->create();

        $this->actingAs($user)
            ->post('/email/verification-notification')
            ->assertRedirect('/mis-reservas');
    }

    /**
     * El destino tras verificar tambien depende del rol. La marca ?verified=1
     * se conserva tal cual estaba.
     */
    public function test_verifying_the_email_lands_an_owner_on_the_dashboard(): void
    {
        $user = User::factory()->owner()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');
    }

    public function test_verifying_the_email_lands_a_customer_on_their_bookings(): void
    {
        $user = User::factory()->customer()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($url)->assertRedirect('/mis-reservas?verified=1');
    }

    /**
     * Un empleado que acepta una invitacion queda autenticado sin haber pasado
     * por el login: el destino tiene que salir de la misma fuente. Con el
     * negocio desactivado, /dashboard fijo lo dejaba en un 403 apenas creada
     * la cuenta.
     */
    public function test_accepting_an_invitation_into_an_inactive_business_falls_back_to_the_landing(): void
    {
        $business = Business::factory()->create(['is_active' => false]);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);
        $invitation = EmployeeInvitation::factory()->for($business)->create([
            'invited_by_id' => $owner->id,
            'email' => 'carla@example.test',
        ]);

        $response = $this->post("/invitations/{$invitation->token}/accept", [
            'token' => $invitation->token,
            'name' => 'Carla Perez',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
        ]);

        $response->assertRedirect('/');
    }
}
