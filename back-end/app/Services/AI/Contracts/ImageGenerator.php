<?php

namespace App\Services\AI\Contracts;

interface ImageGenerator
{
    /** @return array<string, mixed> */
    public function generate(array $context): array;

    public function providerName(): string;
}
