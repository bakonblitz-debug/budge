<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A rule mapping a raw transaction description to a clean merchant display name.
 * Matching mirrors CategoryRule (case-insensitive, five match types).
 */
class MerchantAlias extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'match_type', 'match_value', 'display_name', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function matches(string $description): bool
    {
        $desc = mb_strtolower($description);
        $value = mb_strtolower($this->match_value);

        return match ($this->match_type) {
            'contains' => str_contains($desc, $value),
            'starts_with' => str_starts_with($desc, $value),
            'ends_with' => str_ends_with($desc, $value),
            'exact' => $desc === $value,
            'regex' => (bool) preg_match($this->match_value, $description),
            default => false,
        };
    }
}
