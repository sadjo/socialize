<?php

declare(strict_types=1);

namespace App\Web\Controller\Payload;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Abonnement d'un appareil aux notifications, tel que le navigateur le donne (PushSubscription.toJSON()).
 */
final readonly class PushDevice
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: true)]
        #[Assert\Length(max: 2000)]
        #[Assert\Regex('#^https://#', message: 'Adresse de notification inattendue.')]
        public string $endpoint = '',
        #[Assert\Valid]
        public ?PushDeviceKeys $keys = null,
    ) {
    }
}
