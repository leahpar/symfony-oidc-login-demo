<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\AttributesBasedUserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Reçoit de oidc_login le « sub » et toutes les claims ; dans la vraie appli,
 * c'est ici qu'on cherchera l'Identite (fournisseur, sub) et son Compte.
 *
 * @implements AttributesBasedUserProviderInterface<ProtoUser>
 */
final readonly class ProtoUserProvider implements AttributesBasedUserProviderInterface
{
    public function __construct(
        private string $fournisseur,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function loadUserByIdentifier(string $identifier, array $attributes = []): UserInterface
    {
        // le provider « main » ne sert qu'au rafraîchissement depuis la session
        if ('' === $this->fournisseur || [] === $attributes) {
            throw new UserNotFoundException('Pas de chargement par identifiant seul dans le prototype.');
        }

        return new ProtoUser($this->fournisseur, $identifier, $attributes);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ProtoUser) {
            throw new UnsupportedUserException();
        }

        // rien à recharger : l'utilisateur vit entièrement dans la session
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return ProtoUser::class === $class;
    }
}
