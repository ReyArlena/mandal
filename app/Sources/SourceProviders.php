<?php

namespace App\Sources;

use App\Enums\SourceProviderType;
use App\Models\SourceCredential;

/**
 * Builds a provider client from the stored credential for its type.
 */
class SourceProviders
{
    public function for(SourceProviderType $type): ?SourceProvider
    {
        $credential = SourceCredential::forProvider($type);

        if ($credential === null || $credential->isExpired()) {
            return null;
        }

        return $this->make($type, $credential->token);
    }

    public function make(SourceProviderType $type, #[\SensitiveParameter] string $token): SourceProvider
    {
        return match ($type) {
            SourceProviderType::GitHub => new GitHubProvider($token),
        };
    }
}
