<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\SettingRepository;
use App\Federation\Infrastructure\KeyVault;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Clés de signature : une paire RSA par compte local (créée à la première fédération),
 * et une pour l'acteur d'instance, qui signe les lectures (GET) faites au nom de l'instance.
 */
final readonly class Signer
{
    private const string INSTANCE_KEYS = 'instance_actor_keys';

    public function __construct(
        private KeyVault $vault,
        private EntityManagerInterface $em,
        private SettingRepository $settings,
        private ApUrls $urls,
    ) {
    }

    public function publicKeyOf(Account $account): string
    {
        $this->ensureKeys($account);

        return (string) $account->getPublicKeyPem();
    }

    /**
     * @return array{keyId: string, privateKey: string}
     */
    public function credentialsOf(Account $account): array
    {
        $this->ensureKeys($account);

        return ['keyId' => $this->urls->key($account), 'privateKey' => $this->vault->decrypt((string) $account->getPrivateKeyEncrypted())];
    }

    /**
     * @return array{keyId: string, privateKey: string, publicKey: string}
     */
    public function instance(): array
    {
        $stored = $this->settings->get(self::INSTANCE_KEYS);
        if (null === $stored) {
            $pair = KeyVault::generateRsaKeyPair();
            $stored = json_encode(['public' => $pair['public'], 'private' => $this->vault->encrypt($pair['private'])], \JSON_THROW_ON_ERROR);
            $this->settings->set(self::INSTANCE_KEYS, $stored);
        }
        /** @var array{public: string, private: string} $keys */
        $keys = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);

        return ['keyId' => $this->urls->instanceActor().'#main-key', 'privateKey' => $this->vault->decrypt($keys['private']), 'publicKey' => $keys['public']];
    }

    private function ensureKeys(Account $account): void
    {
        if (!$account->isLocal()) {
            throw new \LogicException('Seuls les comptes locaux signent des requêtes.');
        }
        if (null !== $account->getPrivateKeyEncrypted()) {
            return;
        }
        $pair = KeyVault::generateRsaKeyPair();
        $account->setKeys($pair['public'], $this->vault->encrypt($pair['private']));
        $this->em->flush();
    }
}
