<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Utilisateur du prototype : rien en base, juste l'identité OIDC et ses claims,
 * sérialisés dans la session.
 */
final readonly class ProtoUser implements UserInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public string $fournisseur,
        public string $sub,
        public array $claims,
    ) {
    }

    public function getUserIdentifier(): string
    {
        return $this->fournisseur.':'.$this->sub;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getNomAffiche(): string
    {
        foreach (['name', 'preferred_username', 'email'] as $claim) {
            if (\is_string($this->claims[$claim] ?? null) && '' !== $this->claims[$claim]) {
                return $this->claims[$claim];
            }
        }

        return $this->getUserIdentifier();
    }
}
