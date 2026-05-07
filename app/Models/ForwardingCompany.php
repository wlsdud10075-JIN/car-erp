<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ForwardingCompany extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'contact_name', 'email', 'phone', 'address', 'memo', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
