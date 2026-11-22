<?php

namespace App\Services\AI\Contracts;

interface CopyGenerator
{
    public const MIN_BLOG_WORDS = 1000;

    /** @return array<string, mixed> */
    public function generate(array $context): array;

    public function providerName(): string;
}
