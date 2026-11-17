<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Core\Application\Instance\InstanceAbout;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Markdown\MarkdownRenderer;
use App\Media\Application\InstanceImages;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\MediaRules;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Espace Administration : bloc « Instance » affiché à côté des fils (titre et texte en Markdown).
 */
#[IsGranted(User::ROLE_ADMIN)]
final class InstanceController extends AbstractController
{
    public const string CSRF = 'instance-editor';

    #[Route('/admin/instance', name: 'admin_instance', methods: ['GET', 'POST'])]
    public function index(Request $request, InstanceAbout $about): Response
    {
        $form = $this->createForm(InstanceAboutType::class, ['title' => $about->title(), 'body' => $about->body()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{title: string, body: ?string} $data */
            $data = $form->getData();
            $about->save($data['title'], $data['body'] ?? '');
            $this->addFlash('success', 'Bloc Instance enregistré.');

            return $this->redirectToRoute('admin_instance', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/instance.html.twig', ['form' => $form]);
    }

    /**
     * Image envoyée depuis la barre d'édition ; renvoie son adresse, à insérer dans le Markdown.
     */
    #[Route('/admin/instance/image', name: 'admin_instance_image', methods: ['POST'])]
    public function image(Request $request, InstanceImages $images, ValidatorInterface $validator): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            return $this->json(['error' => 'Jeton de sécurité invalide. Rechargez la page.'], Response::HTTP_FORBIDDEN);
        }
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'Choisissez une image.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $violations = $validator->validate($file, new Assert\File(maxSize: MediaRules::MAX_IMAGE_SIZE, mimeTypes: MediaRules::IMAGE_MIME_TYPES, mimeTypesMessage: 'Choisissez une photo (JPEG, PNG, WebP, HEIC…).'));
        if (\count($violations) > 0) {
            return $this->json(['error' => (string) $violations->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            return $this->json(['url' => $images->store($file)]);
        } catch (MediaProcessingException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * Aperçu du texte, rendu exactement comme sur le site.
     */
    #[Route('/admin/instance/apercu', name: 'admin_instance_preview', methods: ['POST'])]
    public function preview(Request $request, MarkdownRenderer $markdown): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        return new Response($markdown->toHtml(mb_substr($request->request->getString('body'), 0, 5000)));
    }
}
