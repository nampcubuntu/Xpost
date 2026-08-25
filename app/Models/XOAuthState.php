<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XOAuthState extends Model
{
    use BelongsToTeam;

    protected $table = 'x_oauth_states';

    protected $fillable = ['team_id', 'x_app_id', 'user_id', 'state', 'code_verifier', 'redirect_to', 'expires_at'];

    protected $hidden = ['code_verifier'];

    protected function casts(): array
    {
        return [
            'code_verifier' => 'encrypted',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(XApp::class, 'x_app_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
