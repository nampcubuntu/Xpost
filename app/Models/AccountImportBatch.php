<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Model;

class AccountImportBatch extends Model
{
    use BelongsToTeam;

    protected $fillable = ['team_id', 'user_id', 'filename', 'disk', 'path', 'status', 'rows_total', 'job_batch_id'];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'rows_total' => 'integer',
            'rows_imported' => 'integer',
            'rows_failed' => 'integer',
        ];
    }
}
