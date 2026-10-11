<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Facades;

use Illuminate\Support\Facades\Facade;
use Siberfx\XAutopost\XManager;

/**
 * @method static bool isConnected()
 * @method static \Siberfx\XAutopost\Data\Connection connection()
 * @method static \Siberfx\XAutopost\Models\XSharedPost share(\Illuminate\Database\Eloquent\Model&\Siberfx\XAutopost\Contracts\ShareableOnX $model, string $trigger = 'manual')
 * @method static void queue(\Illuminate\Database\Eloquent\Model&\Siberfx\XAutopost\Contracts\ShareableOnX $model)
 * @method static bool wasPosted(\Illuminate\Database\Eloquent\Model $model)
 * @method static bool disconnect()
 * @method static string authorizationUrl(string $state, string $codeChallenge)
 * @method static \Siberfx\XAutopost\Data\Connection completeConnection(string $code, string $codeVerifier)
 *
 * @see XManager
 */
final class X extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return XManager::class;
    }
}
