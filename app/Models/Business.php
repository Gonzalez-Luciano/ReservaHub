<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'timezone', 'currency', 'cancellation_hours', 'logo_path', 'is_active'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * `is_active` tiene default en la base, no en el modelo: sin esto,
     * un modelo recién creado con create() devuelve el atributo en null
     * hasta que se lo relee, y cualquier chequeo de estado lo lee como
     * inactivo.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'cancellation_hours' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }
}
