<?php

declare(strict_types=1);

namespace App\Web\Command;

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lecture d'un mot de passe en console : saisie masquée et confirmée, ou entrée standard
 * (--password-stdin), plus fiable dans un terminal web.
 */
final class PasswordPrompt
{
    public const int MIN_LENGTH = 10;

    /**
     * @param resource|null $stdin
     */
    public static function read(SymfonyStyle $io, bool $fromStdin, $stdin = null): string
    {
        if ($fromStdin) {
            $password = rtrim((string) stream_get_contents($stdin ?? \STDIN), "\r\n");
            self::validate($password);

            return $password;
        }

        $password = $io->askQuestion(self::question('Mot de passe ('.self::MIN_LENGTH.' caractères au minimum)'));
        $confirmation = $io->askQuestion(self::question('Confirmez le mot de passe'));
        if ($password !== $confirmation) {
            throw new \RuntimeException('Les deux saisies ne sont pas identiques. Aucun changement n’a été fait.');
        }
        \assert(\is_string($password));

        return $password;
    }

    private static function question(string $label): Question
    {
        return new Question($label)
            ->setHidden(true)
            ->setHiddenFallback(true)
            ->setMaxAttempts(3)
            ->setValidator(static function (mixed $value): string {
                if (!\is_string($value)) {
                    throw new \RuntimeException('Saisissez un mot de passe.');
                }
                self::validate($value);

                return $value;
            });
    }

    private static function validate(string $password): void
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw new \RuntimeException(\sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_LENGTH));
        }
    }
}
