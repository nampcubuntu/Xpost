<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XAppUsage extends Model
{
    protected $table = 'x_app_usage';

    protected $fillable = ['x_app_id', 'usage_date', 'endpoint', 'requests', 'successes', 'rate_limited', 'errors'];

    protected function casts(): array
    {
        return [
            'usage_date' => 'date',
            'requests' => 'integer',
            'successes' => 'integer',
            'rate_limited' => 'integer',
            'errors' => 'integer',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(XApp::class, 'x_app_id');
    }
}
