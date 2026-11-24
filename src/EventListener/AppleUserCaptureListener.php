<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Apple n'envoie le prénom et le nom qu'une seule fois, à la toute première
 * autorisation, dans le champ POST « user » (JSON) du callback, et jamais dans
 * l'ID token. oidc_login l'ignore : on le met de côté en session avant le firewall.
 *
 * Rien n'authentifie ce champ (il n'est pas signé) : à n'utiliser que comme
 * suggestion de nom, une fois la connexion validée.
 */
final class AppleUserCaptureListener
{
    public const SESSION_KEY = 'apple_user';

    // avant le firewall (priorité 8)
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethod('POST') || '/auth/apple/callback' !== $request->getPathInfo()) {
            return;
        }

        $user = $request->request->getString('user');
        if ('' === $user || !$request->hasSession()) {
            return;
        }

        $decoded = json_decode($user, true);
        if (\is_array($decoded)) {
            $request->getSession()->set(self::SESSION_KEY, $decoded);
        }
    }
}
