<?php

declare(strict_types=1);

namespace App\Controller;

use App\EventListener\AppleUserCaptureListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class ProtoController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('home.html.twig');
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('login.html.twig', [
            'erreur' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/moi', name: 'app_moi')]
    public function moi(Request $request, TokenStorageInterface $tokenStorage): Response
    {
        $token = $tokenStorage->getToken();
        $attributs = [];
        foreach (['oidc_id_token', 'oauth2_access_token', 'oauth2_refresh_token', 'oauth2_access_token_expires_at', 'oidc_sid', 'oidc_acr'] as $nom) {
            $valeur = $token?->hasAttribute($nom) ? $token->getAttribute($nom) : null;
            // jetons tronqués : la page sert à vérifier leur présence, pas à les recopier
            $attributs[$nom] = \is_string($valeur) && \strlen($valeur) > 24 ? substr($valeur, 0, 12).'…'.substr($valeur, -6) : $valeur;
        }

        return $this->render('moi.html.twig', [
            'attributs' => $attributs,
            'firewall' => $token?->getFirewallName() ?? null,
            'appleUser' => $request->getSession()->get(AppleUserCaptureListener::SESSION_KEY),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le firewall.');
    }
}
