<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin page listing all kind 24 feedback messages.
 *
 * Feedback events are published from the /feedback page and persisted
 * both to the database and to the local strfry relay.
 */
#[Route('/admin/feedback', name: 'admin_feedback_')]
#[IsGranted('ROLE_ADMIN')]
class FeedbackAdminController extends AbstractController
{
    private const KIND_FEEDBACK = 24;

    #[Route('', name: 'index')]
    public function index(EventRepository $eventRepository): Response
    {
        $feedbackEvents = $eventRepository->createQueryBuilder('e')
            ->where('e.kind = :kind')
            ->setParameter('kind', self::KIND_FEEDBACK)
            ->orderBy('e.created_at', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/feedback/index.html.twig', [
            'feedbackEvents' => $feedbackEvents,
        ]);
    }
}


