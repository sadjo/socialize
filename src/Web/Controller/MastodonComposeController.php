<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Integration\Mastodon\Application\MastodonException;
use App\Integration\Mastodon\Application\MastodonPublisher;
use App\Integration\Mastodon\Domain\MastodonUser;
use App\Web\Form\MastodonComposeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Publication sur son compte Mastodon, pour une personne connectée avec : envoyée en tâche de fond, elle paraît
 * ensuite sur son profil.
 */
#[IsGranted(MastodonUser::ROLE)]
final class MastodonComposeController extends AbstractController
{
    #[Route('/publier/mastodon', name: 'app_mastodon_compose', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, MastodonPublisher $publisher): Response
    {
        $user = $this->getUser();
        \assert($user instanceof MastodonUser);
        $limits = $publisher->limits($user);
        $default = $user->getDefaultVisibility();
        $form = $this->createForm(MastodonComposeType::class, options: ['limits' => $limits, 'default_visibility' => \in_array($default, ['unlisted', 'private'], true) ? $default : 'public']);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{media: list<UploadedFile>, order: ?string, altTexts: array<int|string, ?string>, caption: ?string, visibility: 'public'|'unlisted'|'private', contentWarning: ?string} $data */
            $data = $form->getData();
            $alts = ComposeSources::altTexts($data['altTexts']);
            $order = ComposeSources::order($data['order']) ?: array_map(static fn (int $i): string => 'n-'.$i, array_keys($data['media']));
            $media = [];
            foreach ($order as $token) {
                if (preg_match('/^n-(\d+)$/', $token, $m) && isset($data['media'][(int) $m[1]])) {
                    $media[] = ['file' => $data['media'][(int) $m[1]], 'alt' => $alts[$token] ?? null];
                }
            }

            try {
                $publisher->publish($user, $media, $data['caption'] ?? '', $data['visibility'], $data['contentWarning']);
                $this->addFlash('success', \sprintf('Publication envoyée sur %s : elle paraîtra sur votre profil dès que votre serveur l’aura publiée.', $user->getAccount()->getDomain()));

                return $this->redirectToRoute('app_profile', ['username' => $user->getAccount()->getAcct()], Response::HTTP_SEE_OTHER);
            } catch (MastodonException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('compose/mastodon.html.twig', ['form' => $form, 'limits' => $limits, 'domain' => $user->getAccount()->getDomain()]);
    }
}
