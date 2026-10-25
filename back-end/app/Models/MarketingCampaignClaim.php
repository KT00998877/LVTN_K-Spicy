<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingCampaignClaim extends Model
{
    protected $fillable = [
        'marketing_campaign_id',
        'user_id',
        'voucher_code',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
