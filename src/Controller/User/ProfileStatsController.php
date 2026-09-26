<?php

declare(strict_types=1);

namespace App\Controller\User;

use App\Repository\VisitRepository;
use Doctrine\DBAL\Exception as DatabaseException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ProfileStatsController extends AbstractController
{
    #[Route('/stats', name: 'profile_stats', methods: ['GET'])]
    public function index(): Response
    {
        return $this->privateResponse('stats/index.html.twig');
    }

    #[Route('/stats/week', name: 'profile_stats_week', methods: ['GET'])]
    public function week(VisitRepository $visitRepository, LoggerInterface $logger): Response
    {
        return $this->period(7, $visitRepository, $logger);
    }

    #[Route('/stats/month', name: 'profile_stats_month', methods: ['GET'])]
    public function month(VisitRepository $visitRepository, LoggerInterface $logger): Response
    {
        return $this->period(30, $visitRepository, $logger);
    }

    #[Route('/stats/chart', name: 'profile_stats_chart', methods: ['GET'])]
    public function chart(VisitRepository $visitRepository, LoggerInterface $logger): Response
    {
        $npub = $this->getUser()?->getUserIdentifier() ?? throw $this->createAccessDeniedException();
        try {
            $data = $visitRepository->getAuthorStatsChart($npub);
        } catch (DatabaseException $exception) {
            return $this->unavailable('stats-chart', $exception, $logger);
        }

        return $this->privateResponse('stats/_chart.html.twig', ['chartData' => $data]);
    }

    private function period(int $days, VisitRepository $visitRepository, LoggerInterface $logger): Response
    {
        $npub = $this->getUser()?->getUserIdentifier() ?? throw $this->createAccessDeniedException();
        $section = $days === 7 ? 'week' : 'month';
        $now = new \DateTimeImmutable();
        try {
            $data = $visitRepository->getAuthorStatsSummary($npub, $days, $now);
            $data['topArticlesLast' . $days . 'Days'] = $visitRepository->getMostVisitedArticlesForNpub(
                $npub, $now->modify('-' . $days . ' days'), 10,
            );
        } catch (DatabaseException $exception) {
            return $this->unavailable('stats-' . $section, $exception, $logger);
        }

        return $this->privateResponse('stats/_' . $section . '.html.twig', $data);
    }

    private function unavailable(string $frameId, DatabaseException $exception, LoggerInterface $logger): Response
    {
        $logger->warning('Author statistics section could not be loaded.', [
            'section' => $frameId,
            'exception' => $exception,
        ]);

        return $this->privateResponse('stats/_error.html.twig', ['frameId' => $frameId], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function privateResponse(string $template, array $data = [], int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, $data, new Response(status: $status));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
