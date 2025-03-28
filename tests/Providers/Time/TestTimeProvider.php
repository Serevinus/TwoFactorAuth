<?php

declare(strict_types=1);

namespace Tests\Providers\Time;

use Serevinus\Auth\Providers\Time\ITimeProvider;

class TestTimeProvider implements ITimeProvider
{
    /** @var int */
    private $time;

    /**
     * @param int $time
     */
    public function __construct($time)
    {
        $this->time = $time;
    }

    /**
     * {@inheritdoc}
     */
    public function getTime()
    {
        return $this->time;
    }
}
