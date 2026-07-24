<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToUser;

    public $timestamps = false;

    protected $fillable = [
        'auditable_type', 'auditable_id', 'action',
        'old_values', 'new_values', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditable()
    {
        return $this->morphTo();
    }
}
