<?php

declare(strict_types=1);

namespace App\Command;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le « client secret » Apple est un JWT ES256 signé avec la clé privée .p8 de
 * l'équipe, valable 6 mois au plus : à régénérer avant expiration.
 *
 * @see https://developer.apple.com/documentation/accountorganizationaldatasharing/creating-a-client-secret
 */
#[AsCommand('app:apple:client-secret', 'Génère le client secret JWT (ES256) de Sign in with Apple')]
final readonly class AppleClientSecretCommand
{
    private const DUREE_MAX = 15_777_000; // 6 mois, limite fixée par Apple

    public function __construct(
        #[Autowire(env: 'APPLE_TEAM_ID')] private string $teamId,
        #[Autowire(env: 'APPLE_KEY_ID')] private string $keyId,
        #[Autowire(env: 'APPLE_CLIENT_ID')] private string $clientId,
        #[Autowire(env: 'APPLE_PRIVATE_KEY_PATH')] private string $privateKeyPath,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Durée de validité en jours (180 au plus)')] int $jours = 180,
    ): int {
        foreach (['APPLE_TEAM_ID' => $this->teamId, 'APPLE_KEY_ID' => $this->keyId, 'APPLE_CLIENT_ID' => $this->clientId, 'APPLE_PRIVATE_KEY_PATH' => $this->privateKeyPath] as $nom => $valeur) {
            if ('' === $valeur || 'a-renseigner' === $valeur) {
                $io->error($nom.' n\'est pas renseigné (.env.local).');

                return 1;
            }
        }

        $maintenant = time();
        $payload = json_encode([
            'iss' => $this->teamId,
            'iat' => $maintenant,
            'exp' => $maintenant + min($jours * 86400, self::DUREE_MAX),
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->clientId,
        ], \JSON_THROW_ON_ERROR);

        $jwk = JWKFactory::createFromKeyFile($this->privateKeyPath);
        $jws = new JWSBuilder(new AlgorithmManager([new ES256()]))
            ->create()
            ->withPayload($payload)
            ->addSignature($jwk, ['alg' => 'ES256', 'kid' => $this->keyId])
            ->build();

        $io->writeln(new CompactSerializer()->serialize($jws, 0));
        $io->note(\sprintf('Expire le %s. À mettre dans APPLE_CLIENT_SECRET (.env.local).', date('Y-m-d', $maintenant + min($jours * 86400, self::DUREE_MAX))));

        return 0;
    }
}
