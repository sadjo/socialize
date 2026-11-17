<?php

declare(strict_types=1);

namespace App\Core\Application\Instance;

use App\Core\Domain\Instance\Setting;
use App\Core\Infrastructure\Doctrine\SettingRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Bloc « Instance » affiché à côté des fils : titre (texte simple) et présentation (Markdown), choisis par
 * l'administrateur. Sans réglage, un texte par défaut.
 */
final class InstanceAbout
{
    public const string TITLE = 'about.title';
    public const string BODY = 'about.body';
    public const string DEFAULT_BODY = 'Réseau photo auto-hébergé, fédéré avec Mastodon et Pixelfed.';

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(
        private readonly SettingRepository $settings,
        #[Autowire(param: 'app.local_domain')]
        private readonly string $localDomain,
    ) {
    }

    public function title(): string
    {
        return $this->values()[self::TITLE] ?? 'Instance '.$this->localDomain;
    }

    public function body(): string
    {
        return $this->values()[self::BODY] ?? self::DEFAULT_BODY;
    }

    public function save(string $title, string $body): void
    {
        $this->settings->set(self::TITLE, trim($title));
        $this->settings->set(self::BODY, trim($body));
        $this->values = null;
    }

    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        if (null === $this->values) {
            $this->values = [];
            /** @var list<Setting> $settings */
            $settings = $this->settings->findBy(['name' => [self::TITLE, self::BODY]]);
            foreach ($settings as $setting) {
                $this->values[$setting->getName()] = $setting->getValue();
            }
        }

        return $this->values;
    }
}
