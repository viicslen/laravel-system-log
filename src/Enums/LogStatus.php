<?php

declare(strict_types=1);

namespace ViicSlen\SystemLog\Enums;

enum LogStatus: string
{
    case Pending = 'pending';
    case Complete = 'complete';
    case Failed = 'failed';
}
