<?php

declare(strict_types=1);

namespace Serevinus\Auth\Providers\Time;

interface ITimeProvider
{
    /**
     * @return int the current timestamp according to this provider
     */
    public function getTime();
}
