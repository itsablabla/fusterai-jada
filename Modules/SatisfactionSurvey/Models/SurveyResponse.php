<?php

namespace Modules\SatisfactionSurvey\Models;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    protected $table = 'survey_responses';

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'rating',
        'comment',
        'ip_address',
        'responded_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'responded_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Ratings of 4 or 5 are considered positive (satisfied). */
    public function isPositive(): bool
    {
        return $this->rating >= 4;
    }
}
