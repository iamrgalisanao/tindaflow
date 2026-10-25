<?php

namespace App\Models;

use Database\Factories\MenuGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of the till's navigation tree (ADR-016): a top group, or a sub-group whose parent is a top group. Never deleted:
 * a group is hidden with `active = false` (invariants.md MENU-005).
 */
class MenuGroup extends Model
{
    /** @use HasFactory<MenuGroupFactory> */
    use HasFactory, HasUuids;

    protected $table = 'menu_groups';

    protected $fillable = ['store_id', 'parent_id', 'name', 'sort_order', 'active'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
