<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GatewayProducts extends Model
{
    use HasFactory;
    protected $table = 'gatewayproducts';

    /// revenuecat_products
    public function revenuecat_products()
    {
        return $this->hasMany(RevenueCatProducts::class, 'gatewayproduct_id', 'id');
    }


}
