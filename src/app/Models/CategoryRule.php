<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryRule extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'category_id', 'match_type', 'match_value', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
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
