<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use ViicSlen\SystemLog\Models\SystemLog;

/**
 * Any Eloquent model that uses the HasSystemLogs trait satisfies this contract.
 *
 * @mixin Model
 */
interface SystemLoggable
{
    /** @return MorphMany<SystemLog, static> */
    public function systemLogs(): MorphMany;
}
