<?php

namespace App\Services\AI\Contracts;

interface CopyGenerator
{
    /** @return array<string, mixed> */
    public function generate(array $context): array;

    public function providerName(): string;
}
