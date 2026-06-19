<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * SPA entry point (decision 8, ADR-026). Serves the built React bundle's
 * `public/app/index.html` for `/app` and every client-side sub-route
 * (`/app/threads/{id}`, …) so deep links and reloads land on the SPA rather
 * than a 404. Client-side routing (react-router) takes over from there.
 *
 * Gated by ROLE_USER on the `main` firewall: anonymous users hit the firewall
 * and are redirected to `/login` (the no-JS server-rendered login stays).
 * Hashed asset requests under `/app/assets/*` are served as static files by
 * FrankenPHP/Caddy in prod and never reach this controller; in dev the Vite
 * server owns them.
 */
final class SpaController extends AbstractController
{
    #[Route(
        '/app/{reactRouting}',
        name: 'app',
        requirements: ['reactRouting' => '.*'],
        defaults: ['reactRouting' => ''],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        $response = new BinaryFileResponse(
            \dirname(__DIR__, 2).'/public/app/index.html',
        );
        // symfony/mime isn't installed, so BinaryFileResponse cannot guess the
        // content type — set it explicitly (always HTML for the SPA entry doc).
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }
}
