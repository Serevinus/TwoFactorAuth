<?php

declare(strict_types=1);

namespace Serevinus\Auth\Providers\Rng;

interface IRNGProvider
{
    public function getRandomBytes(int $bytecount): string;
}
