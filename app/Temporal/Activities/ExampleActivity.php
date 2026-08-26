<?php

namespace App\Temporal\Activities;

class ExampleActivity implements ExampleActivityInterface
{
    public function greet(string $name): string
    {
        return "Hello, {$name}! Processed at " . now()->toDateTimeString();
    }
}
