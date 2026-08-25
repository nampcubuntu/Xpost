<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Database\Factories\AccountGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AccountGroup extends Model
{
    /** @use HasFactory<AccountGroupFactory> */
    use BelongsToTeam, HasFactory;

    protected $fillable = ['team_id', 'name', 'color', 'description'];

    protected function casts(): array
    {
        return ['accounts_count' => 'integer'];
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(XAccount::class, 'account_group_x_account');
    }

    public function syncCount(): void
    {
        $this->newQuery()->whereKey($this->getKey())
            ->update(['accounts_count' => $this->accounts()->count()]);
    }
}
