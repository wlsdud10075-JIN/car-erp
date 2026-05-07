<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $fillable = ['name', 'code', 'currency'];

    public function buyers(): HasMany
    {
        return $this->hasMany(Buyer::class);
    }

    public function consignees(): HasMany
    {
        return $this->hasMany(Consignee::class);
    }
}
