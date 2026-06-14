<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\MembershipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Placeholder authenticated landing page (handoff DoD). Server-rendered Twig,
 * deliberately decoupled from the React SPA at /app/ — the SPA's auth wiring
 * lands with threads in 0.2/0.3.
 */
final class MeController extends AbstractController
{
    public function __construct(private readonly MembershipRepository $memberships)
    {
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $membership = $this->memberships->findOneForUser($user);

        return $this->render('me/index.html.twig', [
            'user' => $user,
            'membership' => $membership,
        ]);
    }
}
