<?php

declare(strict_types=1);

namespace App\Web\Controller\Payload;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Clés de chiffrement d'un abonnement aux notifications (base64url).
 */
final readonly class PushDeviceKeys
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        #[Assert\Regex('/^[A-Za-z0-9_=-]+$/')]
        public string $p256dh = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        #[Assert\Regex('/^[A-Za-z0-9_=-]+$/')]
        public string $auth = '',
    ) {
    }
}
