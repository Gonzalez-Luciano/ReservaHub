<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use Illuminate\Database\Eloquent\Model;

trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function (Model $model) {
            if (! $model->business_id && $business = Business::current()) {
                $model->business_id = $business->id;
            }
        });
    }
}
