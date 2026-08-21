<?php

namespace Tests\Feature\Realtime;

use App\Enums\Role;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml fija BROADCAST_CONNECTION=null, y NullBroadcaster::auth()
        // es un método vacío: autoriza a cualquiera y NUNCA consulta
        // routes/channels.php. Un test de autorización bajo ese driver pasaría
        // siempre sin probar nada. LogBroadcaster::auth() es igual de vacío.
        //
        // Solo esta clase activa el driver real. No hay llamadas de red: el
        // driver 'reverb' es createPusherDriver() -> PusherBroadcaster, cuyo
        // auth() ejecuta el callback del canal y cuyo
        // validAuthenticationResponse() para un canal `private-` solo calcula
        // un HMAC local.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
            'broadcasting.connections.reverb.options.host' => 'reverb.test',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        // routes/channels.php already ran once during application boot, while
        // config('broadcasting.default') was still the suite-wide 'null' from
        // phpunit.xml — so Broadcast::channel() registered our predicate on
        // that NullBroadcaster instance. BroadcastManager caches one
        // Broadcaster instance per driver name and only resolves 'reverb'
        // lazily on first use, so the override above alone leaves the
        // 'reverb' instance with no channels registered at all, which would
        // make every request 403 regardless of the callback's correctness.
        // Re-requiring the channel file now that the default is 'reverb'
        // registers the same predicate on the instance that /broadcasting/auth
        // will actually use.
        require base_path('routes/channels.php');
    }

    private function authorizeAs(User $user, string $channel): TestResponse
    {
        return $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);
    }

    private function activeBusiness(): Business
    {
        return Business::factory()->create(['is_active' => true]);
    }

    public function test_a_guest_cannot_authorize(): void
    {
        $business = $this->activeBusiness();

        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-business.'.$business->id,
        ])->assertStatus(403);
    }

    public function test_an_owner_can_authorize_their_own_business_channel(): void
    {
        $business = $this->activeBusiness();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->authorizeAs($owner, 'private-business.'.$business->id)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_an_admin_can_authorize_their_own_business_channel(): void
    {
        $business = $this->activeBusiness();
        $admin = User::factory()->create(['role' => Role::Admin, 'business_id' => $business->id]);

        $this->authorizeAs($admin, 'private-business.'.$business->id)->assertOk();
    }

    public function test_an_employee_can_authorize_their_own_business_channel(): void
    {
        // Coincide con la autorización HTTP vigente: BookingPolicy::viewAny y
        // Dashboard\BookingController::index dan a cualquier miembro del staff
        // todas las reservas del negocio. Si una fase futura estrecha eso por
        // empleado, el canal se estrecha en el mismo commit y este test cambia
        // de forma consciente.
        $business = $this->activeBusiness();
        $employee = User::factory()->create(['role' => Role::Employee, 'business_id' => $business->id]);

        $this->authorizeAs($employee, 'private-business.'.$business->id)->assertOk();
    }

    public function test_staff_of_another_business_cannot_authorize(): void
    {
        $businessA = $this->activeBusiness();
        $businessB = $this->activeBusiness();
        $intruder = User::factory()->create(['role' => Role::Owner, 'business_id' => $businessB->id]);

        $this->authorizeAs($intruder, 'private-business.'.$businessA->id)->assertStatus(403);
    }

    public function test_a_customer_cannot_authorize_a_staff_channel(): void
    {
        $business = $this->activeBusiness();
        $customer = User::factory()->customer()->create();

        $this->authorizeAs($customer, 'private-business.'.$business->id)->assertStatus(403);
    }

    public function test_a_deactivated_staff_user_cannot_authorize(): void
    {
        $business = $this->activeBusiness();
        $owner = User::factory()->create([
            'role' => Role::Owner,
            'business_id' => $business->id,
            'is_active' => false,
        ]);

        $this->authorizeAs($owner, 'private-business.'.$business->id)->assertStatus(403);
    }

    public function test_staff_of_a_deactivated_business_cannot_authorize(): void
    {
        $business = Business::factory()->create(['is_active' => false]);
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->authorizeAs($owner, 'private-business.'.$business->id)->assertStatus(403);
    }

    public function test_a_zero_padded_business_id_is_rejected(): void
    {
        $business = $this->activeBusiness();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->authorizeAs($owner, 'private-business.0'.$business->id)->assertStatus(403);
    }

    public function test_a_non_numeric_business_id_is_rejected(): void
    {
        $business = $this->activeBusiness();
        $owner = User::factory()->create(['role' => Role::Owner, 'business_id' => $business->id]);

        $this->authorizeAs($owner, 'private-business.'.$business->id.'abc')->assertStatus(403);
    }
}
