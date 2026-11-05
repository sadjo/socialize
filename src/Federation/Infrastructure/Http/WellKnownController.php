<?php

declare(strict_types=1);

namespace App\Federation\Infrastructure\Http;

use App\Core\Application\Account\RegistrationPolicy;
use App\Core\Domain\Post\PostSource;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Federation\Application\ApUrls;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * WebFinger (trouver @nom@instance), NodeInfo (décrire l'instance) et host-meta.
 */
#[AsController]
final readonly class WellKnownController
{
    public const string VERSION = '0.4.0';

    public function __construct(
        private ApUrls $urls,
        private AccountRepository $accounts,
    ) {
    }

    #[Route('/.well-known/webfinger', name: 'ap_webfinger', methods: ['GET'])]
    public function webfinger(Request $request): JsonResponse
    {
        $resource = $request->query->getString('resource');
        $username = null;
        if (preg_match('/^(?:acct:)?@?([^@\s]+)@([^@\s]+)$/i', $resource, $m) && mb_strtolower($m[2]) === $this->urls->domain()) {
            $username = $m[1];
        } elseif (str_starts_with($resource, $this->urls->base().'/users/') || str_starts_with($resource, $this->urls->base().'/@')) {
            $username = (string) preg_replace('#^.*/(?:users/|@)#', '', $resource);
        }

        // Acteur d'instance (convention Mastodon : « domaine@domaine »). Mastodon le vérifie par WebFinger
        // avant d'accepter les lectures signées au nom de l'instance (authorized fetch).
        if (null !== $username && mb_strtolower($username) === $this->urls->domain() || $resource === $this->urls->instanceActor()) {
            return $this->jrd('acct:'.$this->urls->domain().'@'.$this->urls->domain(), $this->urls->instanceActor(), [$this->urls->instanceActor()]);
        }

        $account = null !== $username ? $this->accounts->findLocal($username) : null;
        if (!$account instanceof \App\Core\Domain\Account\Account) {
            return new JsonResponse(['error' => 'Compte inconnu.'], Response::HTTP_NOT_FOUND);
        }

        return $this->jrd(
            'acct:'.$account->getUsername().'@'.$this->urls->domain(),
            $this->urls->actor($account),
            [$this->urls->actor($account), $this->urls->profile($account)],
            $this->urls->profile($account),
        );
    }

    /**
     * @param list<string> $aliases
     */
    private function jrd(string $subject, string $actor, array $aliases, ?string $profile = null): JsonResponse
    {
        $links = [['rel' => 'self', 'type' => 'application/activity+json', 'href' => $actor]];
        if (null !== $profile) {
            $links[] = ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html', 'href' => $profile];
        }
        $response = new JsonResponse(
            ['subject' => $subject, 'aliases' => $aliases, 'links' => $links],
            headers: ['Content-Type' => 'application/jrd+json; charset=utf-8', 'Access-Control-Allow-Origin' => '*'],
        );
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES);

        return $response;
    }

    #[Route('/.well-known/host-meta', name: 'ap_host_meta', methods: ['GET'])]
    public function hostMeta(): Response
    {
        $template = $this->urls->base().'/.well-known/webfinger?resource={uri}';

        return new Response(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<XRD xmlns="http://docs.oasis-open.org/ns/xri/xrd-1.0"><Link rel="lrdd" template="'.htmlspecialchars($template).'"/></XRD>',
            headers: ['Content-Type' => 'application/xrd+xml; charset=utf-8'],
        );
    }

    #[Route('/.well-known/nodeinfo', name: 'ap_nodeinfo_links', methods: ['GET'])]
    public function nodeinfoLinks(): JsonResponse
    {
        $response = new JsonResponse(['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => $this->urls->base().'/nodeinfo/2.1']]]);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES);

        return $response;
    }

    #[Route('/nodeinfo/2.1', name: 'ap_nodeinfo', methods: ['GET'])]
    public function nodeinfo(UserRepository $users, PostRepository $posts, RegistrationPolicy $registration): JsonResponse
    {
        $response = new JsonResponse([
            'version' => '2.1',
            'software' => ['name' => 'socialize', 'version' => self::VERSION, 'homepage' => $this->urls->base()],
            'protocols' => ['activitypub'],
            'services' => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => $registration->isOpen(),
            'usage' => [
                'users' => ['total' => $users->count([])],
                'localPosts' => $posts->count(['source' => PostSource::Local]),
            ],
            'metadata' => ['nodeName' => 'Socialize · '.$this->urls->domain()],
        ], headers: ['Content-Type' => 'application/json; profile="http://nodeinfo.diaspora.software/ns/schema/2.1#"']);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES);

        return $response;
    }
}
