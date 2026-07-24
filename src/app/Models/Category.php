<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToUser, HasFactory;

    /**
     * Allowed values for the need-vs-want `kind` axis. Single source of truth for
     * the migration enum, request validation, the seeder, and the UI selector.
     *
     * `income` is distinct from `excluded`: both are kept out of the 4-way spend
     * math, but income categories must be resolvable on their own (for the income
     * ledger and the 50/30/10/10 income basis), whereas `excluded` is reserved for
     * transfers / inter-account money movement.
     *
     * @var array<int, string>
     */
    public const KINDS = ['need', 'want', 'saving', 'investment', 'excluded', 'income'];

    protected $fillable = [
        'parent_id', 'name', 'icon', 'color', 'sort_order', 'is_active', 'kind',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function rules()
    {
        return $this->hasMany(CategoryRule::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function budget()
    {
        return $this->hasOne(Budget::class)->where('is_active', true)->latest('start_date');
    }

    public function fixedExpenses()
    {
        return $this->hasMany(FixedExpense::class);
    }

    public function envelopePool()
    {
        return $this->hasOne(EnvelopePool::class)->where('is_active', true);
    }

    public function isParent(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Ids of every category of the given `kind`, plus any of their children
     * (children may be left untagged in the UI but still belong to the tree).
     * Resolved by kind — not by name — so it's duplicate-safe (the DB can hold
     * more than one same-named tree) and consistent across every consumer
     * (income ledger, dashboard transfer exclusion, budget rule, insights).
     *
     * @return array<int, int>
     */
    public static function idsOfKind(string $kind): array
    {
        $parentIds = self::query()->where('kind', $kind)->pluck('id');

        return self::query()
            ->where('kind', $kind)
            ->orWhereIn('parent_id', $parentIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Order categories for a picker: most-used first, then the manual
     * sort_order, then name. The transaction count is computed on the fly and
     * scoped to the current user via the BelongsToUser global scope on both
     * Category and Transaction.
     */
    public function scopeOrderByUsage(Builder $query): Builder
    {
        return $query->withCount('transactions')
            ->orderByDesc('transactions_count')
            ->orderBy('sort_order')
            ->orderBy('name');
    }
}
