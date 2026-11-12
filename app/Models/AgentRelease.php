<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A signed release of the agent for one platform (App\Agents\Releases).
 *
 * @property string $id
 * @property string $version
 * @property string $platform
 * @property string|null $path file on the agent-releases disk
 * @property string|null $url or an https URL elsewhere
 * @property string $sha512
 * @property int $size
 * @property string $signature Ed25519, hex
 * @property string|null $notes
 * @property CarbonImmutable $published_at
 */
class AgentRelease extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }
}
