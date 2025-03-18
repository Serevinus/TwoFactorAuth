<?php

declare(strict_types=1);

namespace Serevinus\Auth\Providers\Time;

class LocalMachineTimeProvider implements ITimeProvider
{
    public function getTime()
    {
        return time();
    }
}
