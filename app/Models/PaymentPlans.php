<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentPlans extends Model
{
    protected $table = 'plans';

    protected $guarded = [];

    // gateway_products
    public function gateway_products()
    {
        return $this->hasMany(GatewayProducts::class, 'plan_id', 'id');
    }

    // revenuecat_products
    public function revenuecat_products()
    {
        return $this->hasMany(RevenueCatProducts::class, 'plan_id', 'id');
    }
}
