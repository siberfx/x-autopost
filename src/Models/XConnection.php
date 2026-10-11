<?php

declare(strict_types=1);

namespace Siberfx\XAutopost\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $access_token
 * @property string|null $refresh_token
 * @property string|null $username
 * @property string|null $name
 * @property string|null $picture
 * @property list<string>|null $scopes
 * @property CarbonImmutable|null $connected_at
 */
final class XConnection extends Model
{
    protected $table = 'x_connections';

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'scopes' => 'array',
            'connected_at' => 'immutable_datetime',
        ];
    }
}
