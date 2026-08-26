<?php

namespace App\Temporal\Activities;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface]
interface ExampleActivityInterface
{
    #[ActivityMethod]
    public function greet(string $name): string;
}
