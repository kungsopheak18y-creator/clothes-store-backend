<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'total_amount',
        'status',
        'notes',
        'qr_string',
        'md5',
        'qr_expires_at',
    ];

    protected $casts = [
        'qr_expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function addresses()
    {
    return $this->hasMany(Address::class);
    }
}