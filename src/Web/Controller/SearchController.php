<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Federation\Application\RemoteAccounts;
use App\Federation\Infrastructure\FederationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    #[Route('/recherche', name: 'app_search', methods: ['GET'])]
    public function __invoke(Request $request, AccountRepository $accounts, RemoteAccounts $remote): Response
    {
        $query = trim($request->query->getString('q'));
        $results = [];
        $error = null;

        if (preg_match('/^@?([A-Za-z0-9_.\-]+)@([a-z0-9\-]+(?:\.[a-z0-9\-]+)+)$/i', $query, $m)) {
            // Adresse du fédivers : on la résout par WebFinger, puis on ouvre le profil.
            try {
                $account = $remote->byHandle($m[1], $m[2]);

                return $this->redirectToRoute('app_profile', ['username' => $account->getAcct()]);
            } catch (FederationException) {
                $error = \sprintf('Le compte @%s@%s est introuvable, ou son instance ne répond pas.', $m[1], $m[2]);
            }
        } elseif ('' !== $query) {
            $results = $accounts->search(ltrim($query, '@'));
            if ([] === $results) {
                $error = 'Aucun compte ne correspond. Pour un compte d’une autre instance, saisissez son adresse complète (@nom@instance).';
            }
        }

        return $this->render('search/index.html.twig', ['results' => $results, 'error' => $error]);
    }
}
