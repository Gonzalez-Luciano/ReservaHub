<?php

namespace App\Models\Scopes;

use App\Exceptions\MissingBusinessContextException;
use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($business = Business::current()) {
            $builder->where($model->getTable().'.business_id', $business->id);

            return;
        }

        if (! app()->runningInConsole()) {
            throw MissingBusinessContextException::forModel($model::class);
        }
    }
}
