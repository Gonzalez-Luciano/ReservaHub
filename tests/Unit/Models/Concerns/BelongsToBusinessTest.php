<?php

namespace Tests\Unit\Models\Concerns;

use App\Exceptions\MissingBusinessContextException;
use App\Models\Business;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Tests\TestCase;

class BelongsToBusinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scope_test_models', function ($table) {
            $table->id();
            $table->foreignId('business_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scope_test_models');

        parent::tearDown();
    }

    public function test_query_is_scoped_to_current_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        ScopeTestModel::unguard();
        ScopeTestModel::create(['business_id' => $businessA->id, 'name' => 'A']);
        ScopeTestModel::create(['business_id' => $businessB->id, 'name' => 'B']);
        ScopeTestModel::reguard();

        app()->instance(Business::class, $businessA);

        $this->assertSame(1, ScopeTestModel::count());
        $this->assertSame('A', ScopeTestModel::first()->name);
    }

    public function test_query_throws_when_no_business_bound_outside_console(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        ScopeTestModel::unguard();
        ScopeTestModel::create(['business_id' => $businessA->id, 'name' => 'A']);
        ScopeTestModel::create(['business_id' => $businessB->id, 'name' => 'B']);
        ScopeTestModel::reguard();

        $this->setRunningInConsole(false);

        $this->expectException(MissingBusinessContextException::class);
        $this->expectExceptionMessage(ScopeTestModel::class);

        ScopeTestModel::count();
    }

    public function test_query_is_unfiltered_when_no_business_bound_in_console(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        ScopeTestModel::unguard();
        ScopeTestModel::create(['business_id' => $businessA->id, 'name' => 'A']);
        ScopeTestModel::create(['business_id' => $businessB->id, 'name' => 'B']);
        ScopeTestModel::reguard();

        $this->setRunningInConsole(true);

        $this->assertSame(2, ScopeTestModel::count());
    }

    private function setRunningInConsole(bool $value): void
    {
        $property = new ReflectionProperty(app(), 'isRunningInConsole');
        $property->setAccessible(true);
        $property->setValue(app(), $value);
    }

    public function test_business_id_is_auto_filled_on_create(): void
    {
        $business = Business::factory()->create();
        app()->instance(Business::class, $business);

        ScopeTestModel::unguard();
        $model = ScopeTestModel::create(['name' => 'Auto']);
        ScopeTestModel::reguard();

        $this->assertSame($business->id, $model->business_id);
    }
}

class ScopeTestModel extends Model
{
    use BelongsToBusiness;

    protected $table = 'scope_test_models';
}
