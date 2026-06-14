<?php

declare(strict_types=1);

namespace App\Ai\ModelProfile;

final class UnknownModelProfile extends \RuntimeException
{
    public static function named(string $profile): self
    {
        return new self(\sprintf('Unknown model profile "%s". Configure it under proxenos.model_profiles.', $profile));
    }
}
