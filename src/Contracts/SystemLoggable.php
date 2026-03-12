<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Any Eloquent model that uses the HasSystemLogs trait satisfies this contract.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
interface SystemLoggable
{
    /** @return MorphMany<\ViicSlen\SystemLog\Models\SystemLog, static> */
    public function systemLogs(): MorphMany;
}
