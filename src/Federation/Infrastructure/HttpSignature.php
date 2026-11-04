<?php

declare(strict_types=1);

namespace App\Federation\Infrastructure;

/**
 * Signatures HTTP au format « draft-cavage » (rsa-sha256), celui de Mastodon et de Pixelfed.
 */
final class HttpSignature
{
    /** Écart toléré entre l'en-tête Date et l'heure du serveur. */
    private const int MAX_CLOCK_SKEW = 12 * 3600;

    /**
     * En-têtes à ajouter à une requête signée.
     *
     * @return array<string, string>
     */
    public static function sign(string $method, string $url, ?string $body, string $keyId, string $privateKeyPem, ?\DateTimeImmutable $now = null): array
    {
        $parts = parse_url($url);
        $host = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $target = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $headers = [
            '(request-target)' => mb_strtolower($method).' '.$target,
            'host' => $host,
            'date' => ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T'),
        ];
        if (null !== $body) {
            $headers['digest'] = 'SHA-256='.base64_encode(hash('sha256', $body, true));
        }

        $signed = implode("\n", array_map(static fn (string $k, string $v): string => $k.': '.$v, array_keys($headers), $headers));
        if (!openssl_sign($signed, $signature, $privateKeyPem, \OPENSSL_ALGO_SHA256) || !\is_string($signature)) {
            throw new \RuntimeException('Signature HTTP impossible : clé privée invalide.');
        }

        $out = [
            'Host' => $headers['host'],
            'Date' => $headers['date'],
            'Signature' => \sprintf('keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"', $keyId, implode(' ', array_keys($headers)), base64_encode($signature)),
        ];
        if (isset($headers['digest'])) {
            $out['Digest'] = $headers['digest'];
        }

        return $out;
    }

    /**
     * @return array{keyId: string, headers: list<string>, signature: string}|null
     */
    public static function parse(?string $header): ?array
    {
        if (null === $header || '' === $header) {
            return null;
        }
        preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, \PREG_SET_ORDER);
        $params = [];
        foreach ($matches as $m) {
            $params[$m[1]] = $m[2];
        }
        if (!isset($params['keyId'], $params['signature'])) {
            return null;
        }

        return [
            'keyId' => $params['keyId'],
            'headers' => array_values(array_filter(explode(' ', mb_strtolower($params['headers'] ?? 'date')))),
            'signature' => $params['signature'],
        ];
    }

    /**
     * Vérifie une requête reçue.
     *
     * @param array<string, string> $requestHeaders en-têtes en minuscules
     *
     * @throws SignatureException
     */
    public static function verify(string $method, string $path, array $requestHeaders, string $body, string $publicKeyPem, ?\DateTimeImmutable $now = null): void
    {
        $parsed = self::parse($requestHeaders['signature'] ?? null) ?? throw new SignatureException('Signature HTTP absente.');

        if ('post' === mb_strtolower($method)) {
            if (!\in_array('digest', $parsed['headers'], true) || !\in_array('(request-target)', $parsed['headers'], true)) {
                throw new SignatureException('La signature doit couvrir (request-target) et Digest.');
            }
            $expected = 'SHA-256='.base64_encode(hash('sha256', $body, true));
            if (!hash_equals($expected, $requestHeaders['digest'] ?? '')) {
                throw new SignatureException('Le Digest ne correspond pas au corps de la requête.');
            }
        }

        if (\in_array('date', $parsed['headers'], true)) {
            $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $requestHeaders['date'] ?? '', new \DateTimeZone('UTC'));
            $now ??= new \DateTimeImmutable();
            if (false === $date || abs($now->getTimestamp() - $date->getTimestamp()) > self::MAX_CLOCK_SKEW) {
                throw new SignatureException('En-tête Date absent ou trop éloigné de l’heure du serveur.');
            }
        }

        $lines = [];
        foreach ($parsed['headers'] as $name) {
            $value = '(request-target)' === $name ? mb_strtolower($method).' '.$path : ($requestHeaders[$name] ?? null);
            if (null === $value) {
                throw new SignatureException(\sprintf('En-tête signé absent : %s.', $name));
            }
            $lines[] = $name.': '.$value;
        }

        $signature = base64_decode($parsed['signature'], true);
        if (false === $signature || 1 !== openssl_verify(implode("\n", $lines), $signature, $publicKeyPem, \OPENSSL_ALGO_SHA256)) {
            throw new SignatureException('Signature HTTP invalide.');
        }
    }
}
