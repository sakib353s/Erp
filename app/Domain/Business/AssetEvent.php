<?php

namespace App\Domain\Business;

use App\Domain\Foundation\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12-14 — the asset's own history: it was registered, moved, handed over,
 * capitalised, depreciated, taken on a trip, or disposed of.
 *
 * “Where has this generator been?” is a question the register should answer
 * without a search through audit payloads, and “who had it when it stopped
 * working?” is a question somebody asks the day after. Both are answered here,
 * in the register's own words, next to the asset.
 */
class AssetEvent extends Model
{
    public const ACTIONS = [
        'created' => 'Registered',
        'updated' => 'Corrected',
        'moved' => 'Moved',
        'assigned' => 'Custody changed',
        'capitalised' => 'Capitalised',
        'depreciated' => 'Depreciated',
        'trip' => 'Trip logged',
        'record_linked' => 'Paper linked',
        'disposed' => 'Disposed',
    ];

    /**
     * The history of the asset register, named for the register it describes:
     * one `business_asset_events` row per thing that happened to an asset, so
     * a report can join the register without guessing at a second prefix.
     */
    protected $table = 'business_asset_events';

    protected $fillable = [
        'company_id', 'business_asset_id', 'action', 'happened_on', 'note', 'meta', 'actor_id',
    ];

    protected $casts = [
        'happened_on' => 'date',
        'meta' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(BusinessAsset::class, 'business_asset_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? ucfirst((string) $this->action);
    }
}
