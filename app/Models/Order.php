<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'total_amount', 'status', 
        'shipping_address', 'payment_method', 'notes'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'archived_at' => 'datetime',
    ];

    /**
     * Orders that are not archived (normal admin/customer/seller listings).
     */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Orders archived by a system admin (hidden from admin lists only).
     */
    public function scopeArchived($query)
    {
        return $query->whereNotNull('archived_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
