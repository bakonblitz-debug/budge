<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToUser;

    protected $fillable = ['key', 'value', 'type'];

    public function getTypedValueAttribute(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->typed_value : $default;
    }

    public static function setValue(string $key, mixed $value, string $type = 'string'): static
    {
        $storeValue = $type === 'json' ? json_encode($value) : (string) $value;

        return static::updateOrCreate(
            ['key' => $key, 'user_id' => auth()->id()],
            ['value' => $storeValue, 'type' => $type]
        );
    }
}
