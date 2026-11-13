<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something Home Helper asked a device to do (App\SmartHome\Control).
 *
 * @property string $id
 * @property string $household_id
 * @property string $device_id
 * @property int|null $requested_by
 * @property string $source dashboard|api|rule|assistant
 * @property string|null $rule_id
 * @property string $action turn_on|turn_off|toggle|set_brightness|lock|unlock
 * @property array<string, mixed>|null $params
 * @property string $status done|failed|awaiting_approval|approved|declined|expired
 * @property string|null $error
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $duration_ms
 * @property CarbonImmutable $created_at
 * @property-read SmartDevice $device
 * @property-read User|null $requester
 * @property-read User|null $decider
 */
class SmartHomeAction extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'household_id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'decided_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SmartDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SmartDevice::class, 'device_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** "Turn on Living room lamp at 40%" */
    public function label(): string
    {
        $name = $this->device->name;

        return match ($this->action) {
            'turn_on' => "Turn on {$name}",
            'turn_off' => "Turn off {$name}",
            'toggle' => "Switch {$name}",
            'set_brightness' => "Set {$name} to ".($this->params['brightness'] ?? '?').'%',
            'lock' => "Lock {$name}",
            'unlock' => "Unlock {$name}",
            default => "{$this->action} {$name}",
        };
    }
}
