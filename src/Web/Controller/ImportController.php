<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Social\AudienceGroups;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Import\Instagram\Application\ArchiveImporter;
use App\Import\Instagram\Application\ArchiveUploads;
use App\Import\Instagram\Application\ChunkRejected;
use App\Import\Instagram\Application\ImportException;
use App\Import\Instagram\Application\ImportPreviews;
use App\Import\Instagram\Domain\ImportItem;
use App\Import\Instagram\Domain\ImportStatus;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Domain\Placement;
use App\Import\Instagram\Infrastructure\Doctrine\ImportItemRepository;
use App\Import\Instagram\Infrastructure\Doctrine\InstagramImportRepository;
use App\Web\Controller\Payload\StartImport;
use App\Web\Form\ImportOptionsType;
use App\Web\Security\CurrentAccount;
use App\Web\Security\ImportVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Import d'une archive Instagram : envoi par morceaux, analyse, options, choix publication par publication, import.
 */
#[IsGranted('ROLE_USER')]
final class ImportController extends AbstractController
{
    /** Jeton des envois (JavaScript) : en-tête X-CSRF-Token. */
    private const string UPLOAD_TOKEN = 'import-upload';

    public function __construct(
        private readonly InstagramImportRepository $imports,
        private readonly CurrentAccount $currentAccount,
        private readonly PostRepository $posts,
        private readonly StoryRepository $stories,
    ) {
    }

    #[Route('/import', name: 'app_import', methods: ['GET'])]
    public function index(ImportItemRepository $items): Response
    {
        $account = $this->currentAccount->require();
        $active = $this->imports->activeFor($account);
        if ($active instanceof InstagramImport && ImportStatus::Uploading !== $active->getStatus()) {
            return $this->redirectToRoute('app_import_show', ['id' => $active->getPublicId()]);
        }
        // Import terminé mais vidéos encore en traitement : sa progression reste affichée.
        $latest = $this->imports->finishedFor($account, 1)[0] ?? null;
        if (!$active instanceof InstagramImport && $latest instanceof InstagramImport && $items->videoProgress($latest)['processing'] > 0) {
            return $this->redirectToRoute('app_import_show', ['id' => $latest->getPublicId()]);
        }

        return $this->render('import/start.html.twig', [
            'resume' => $active,
            'finished' => $this->imports->finishedFor($account, 50),
        ]);
    }

    #[Route('/import/envoi', name: 'app_import_begin', methods: ['POST'])]
    public function begin(#[MapRequestPayload] StartImport $payload, Request $request, ArchiveUploads $uploads): JsonResponse
    {
        $this->checkUploadToken($request);
        try {
            $import = $uploads->begin($this->currentAccount->require(), array_map(static fn (Payload\ArchivePart $p): array => ['name' => $p->name, 'size' => $p->size], $payload->parts));
        } catch (ImportException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->uploadState($import));
    }

    #[Route('/import/{id}/parties/{index}', name: 'app_import_chunk', requirements: ['id' => Requirement::UID_BASE58, 'index' => '\d{1,2}'], methods: ['POST'])]
    public function chunk(
        string $id,
        int $index,
        Request $request,
        ArchiveUploads $uploads,
        #[Autowire(service: 'profiler')] ?Profiler $profiler = null,
    ): JsonResponse {
        // Le profileur (développement) garderait en mémoire chaque morceau de 50 Mo.
        $profiler?->disable();
        $this->checkUploadToken($request);
        $import = $this->import($id);
        $chunk = $request->getContent(true);
        try {
            $received = $uploads->append($import, $index, $request->query->getInt('debut'), $chunk);
        } catch (ChunkRejected $e) {
            return $this->json(['received' => $e->expectedOffset, 'error' => $e->getMessage()], Response::HTTP_CONFLICT);
        } catch (ImportException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } finally {
            if (\is_resource($chunk)) {
                fclose($chunk);
            }
        }

        return $this->json(['received' => $received, 'status' => $import->getStatus()->value]);
    }

    #[Route('/import/{id}/etat', name: 'app_import_status', requirements: ['id' => Requirement::UID_BASE58], methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        return $this->json($this->uploadState($this->import($id)));
    }

    #[Route('/import/{id}', name: 'app_import_show', requirements: ['id' => Requirement::UID_BASE58], methods: ['GET', 'POST'])]
    public function show(string $id, Request $request, ArchiveImporter $importer, ImportItemRepository $items, AudienceGroups $groups): Response
    {
        $import = $this->import($id);
        $form = null;
        $posts = [];
        if (ImportStatus::Ready === $import->getStatus()) {
            $posts = $items->posts($import);
            $accountGroups = $groups->of($import->getAccount());
            $form = $this->createForm(ImportOptionsType::class, [
                'placement' => $import->getPlacement(),
                'defaultGroup' => $import->getDefaultGroup(),
                'keepOriginalDates' => true,
                'importStories' => true,
            ], ['groups' => $accountGroups]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array{placement: Placement, defaultGroup: ?string, keepOriginalDates: bool, importStories: bool} $data */
                $data = $form->getData();
                // Une liste déroulante par publication : vide = la visibilité de tout l'import.
                $choices = $request->request->all('choix');
                // Et son groupe pour « Abonnés seulement » : vide = groupe par défaut, « all » = tous les abonnés.
                $groupChoices = $request->request->all('groupe');
                $groupIds = array_map(static fn (AudienceGroup $g): string => $g->getPublicId(), $accountGroups);
                foreach ($posts as $post) {
                    $value = $choices[$post->getPublicId()] ?? null;
                    $post->choose(\is_string($value) ? Placement::tryFrom($value) : $post->getChoice());
                    $group = $groupChoices[$post->getPublicId()] ?? null;
                    if (\is_string($group)) {
                        $post->chooseGroup(ImportItem::GROUP_ALL === $group || \in_array($group, $groupIds, true) ? $group : null);
                    }
                }
                $importer->launch($import, $data['placement'], $data['keepOriginalDates'], $data['importStories'], $data['defaultGroup']);

                return $this->redirectToRoute('app_import_show', ['id' => $id], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('import/show.html.twig', $this->report($import, $items) + [
            'import' => $import,
            'form' => $form,
            'posts' => $posts,
            'placements' => Placement::cases(),
            'groups' => $form instanceof \Symfony\Component\Form\FormInterface ? $groups->of($import->getAccount()) : [],
            'progress' => $items->countByStatus($import),
            'videos' => $items->videoProgress($import),
        ]);
    }

    #[Route('/import/{id}/progression', name: 'app_import_progress', requirements: ['id' => Requirement::UID_BASE58], methods: ['GET'])]
    public function progress(string $id, ImportItemRepository $items): Response
    {
        $import = $this->import($id);

        return $this->render('import/_progress.html.twig', [
            'in_frame' => true,
            'import' => $import,
            'progress' => $items->countByStatus($import),
            'videos' => $items->videoProgress($import),
        ]);
    }

    #[Route('/import/{id}/apercu/{item}', name: 'app_import_preview', requirements: ['id' => Requirement::UID_BASE58, 'item' => Requirement::UID_BASE58], methods: ['GET'])]
    public function preview(string $id, string $item, ImportItemRepository $items, ImportPreviews $previews): BinaryFileResponse
    {
        $element = $items->findInImport($this->import($id), $item);
        $path = $element instanceof ImportItem ? $previews->thumbnail($element) : null;
        if (null === $path) {
            throw new NotFoundHttpException('Pas d’aperçu pour cet élément.');
        }
        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/webp']);
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }

    #[Route('/import/{id}/abandonner', name: 'app_import_cancel', requirements: ['id' => Requirement::UID_BASE58], methods: ['POST'])]
    public function cancel(string $id, Request $request, ArchiveUploads $uploads): RedirectResponse
    {
        $import = $this->import($id);
        if (!$this->isCsrfTokenValid('import-cancel-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $uploads->cancel($import);
        $this->addFlash('success', 'Import abandonné. Les publications déjà importées restent sur votre profil.');

        return $this->redirectToRoute('app_import', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Détail d'un import fini : chaque élément, avec la publication ou la story créée (null si supprimée depuis).
     *
     * @return array{elements: list<ImportItem>, targets: array<string, Post|Story>}
     */
    private function report(InstagramImport $import, ImportItemRepository $items): array
    {
        if ($import->getStatus()->isActive()) {
            return ['elements' => [], 'targets' => []];
        }
        $elements = $items->allOf($import);
        $ids = array_values(array_filter(array_map(static fn (ImportItem $i): ?Uuid => $i->getTargetId(), $elements)));
        $targets = [];
        if ([] !== $ids) {
            foreach ([...$this->posts->findBy(['id' => $ids]), ...$this->stories->findBy(['id' => $ids])] as $target) {
                $targets[$target->getId()->toRfc4122()] = $target;
            }
        }

        return ['elements' => $elements, 'targets' => $targets];
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadState(InstagramImport $import): array
    {
        $id = $import->getPublicId();

        return [
            'id' => $id,
            'status' => $import->getStatus()->value,
            'show_url' => $this->generateUrl('app_import_show', ['id' => $id]),
            'parts' => array_map(fn (array $part, int $index): array => [
                'name' => $part['name'],
                'size' => $part['size'],
                'received' => $part['received'],
                'url' => $this->generateUrl('app_import_chunk', ['id' => $id, 'index' => $index]),
            ], $import->getParts(), array_keys($import->getParts())),
        ];
    }

    private function import(string $id): InstagramImport
    {
        $import = $this->imports->findByPublicId($id);
        if (!$import instanceof InstagramImport || !$this->isGranted(ImportVoter::MANAGE, $import)) {
            throw new NotFoundHttpException('Cet import n’existe pas.');
        }

        return $import;
    }

    private function checkUploadToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::UPLOAD_TOKEN, (string) $request->headers->get('X-CSRF-Token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
    }
}
