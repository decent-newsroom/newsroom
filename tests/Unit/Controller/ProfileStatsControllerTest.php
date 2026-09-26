<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\User\ProfileStatsController;
use App\Repository\VisitRepository;
use Doctrine\DBAL\Exception as DatabaseException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

final class ProfileStatsControllerTest extends TestCase
{
    public function testShellRendersWithoutAnyAnalyticsDependency(): void
    {
        $controller = $this->getMockBuilder(ProfileStatsController::class)
            ->onlyMethods(['render', 'getUser'])->getMock();
        $controller->expects(self::never())->method('getUser');
        $controller->expects(self::once())->method('render')
            ->with('stats/index.html.twig', [], self::isInstanceOf(Response::class))
            ->willReturnCallback(static fn ($template, $data, $response) => $response);

        $this->assertPrivateResponse($controller->index());
    }

    /** @dataProvider periods */
    public function testPeriodLoadsOnlyItsOwnDataForTheAuthenticatedOwner(string $section, int $days): void
    {
        $repository = $this->createMock(VisitRepository::class);
        $summary = ['visitsLast' . $days . 'Days' => 12];
        $now = null;
        $repository->expects(self::once())->method('getAuthorStatsSummary')
            ->with('npub-owner', $days, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnCallback(static function ($npub, $period, $instant) use ($summary, &$now) {
                $now = $instant;
                return $summary;
            });
        $repository->expects(self::once())->method('getMostVisitedArticlesForNpub')
            ->with('npub-owner', self::callback(static function ($since) use (&$now, $days): bool { return $since == $now?->modify('-' . $days . ' days'); }), 10)
            ->willReturn([['route' => '/p/npub-owner/d/article', 'count' => 12]]);
        $repository->expects(self::never())->method('getAuthorStatsChart');

        $controller = $this->controllerForUser();
        $controller->expects(self::once())->method('render')
            ->with('stats/_' . $section . '.html.twig', $summary + [
                'topArticlesLast' . $days . 'Days' => [['route' => '/p/npub-owner/d/article', 'count' => 12]],
            ], self::isInstanceOf(Response::class))
            ->willReturnCallback(static fn ($template, $data, $response) => $response);

        $this->assertPrivateResponse($controller->$section($repository, new NullLogger()));
    }

    public static function periods(): iterable
    {
        yield 'weekly' => ['week', 7];
        yield 'monthly' => ['month', 30];
    }

    public function testChartLoadsOnlyDailyDataForTheAuthenticatedOwner(): void
    {
        $repository = $this->createMock(VisitRepository::class);
        $chart = [['day' => '2026-09-26', 'visits' => 3, 'uniqueVisitors' => 2]];
        $repository->expects(self::once())->method('getAuthorStatsChart')->with('npub-owner')->willReturn($chart);
        $repository->expects(self::never())->method('getAuthorStatsSummary');
        $repository->expects(self::never())->method('getMostVisitedArticlesForNpub');
        $controller = $this->controllerForUser();
        $controller->expects(self::once())->method('render')
            ->with('stats/_chart.html.twig', ['chartData' => $chart], self::isInstanceOf(Response::class))
            ->willReturnCallback(static fn ($template, $data, $response) => $response);

        $this->assertPrivateResponse($controller->chart($repository, new NullLogger()));
    }

    /** @dataProvider sections */
    public function testDatabaseFailureReturnsOnlyTheFailedFrame(string $section): void
    {
        $repository = $this->createMock(VisitRepository::class);
        $repository->method($section === 'chart' ? 'getAuthorStatsChart' : 'getAuthorStatsSummary')
            ->willThrowException(new class('statement timeout') extends \RuntimeException implements DatabaseException {});
        $repository->expects(self::never())->method('getMostVisitedArticlesForNpub');
        $controller = $this->controllerForUser();
        $controller->expects(self::once())->method('render')
            ->with('stats/_error.html.twig', ['frameId' => 'stats-' . $section], self::isInstanceOf(Response::class))
            ->willReturnCallback(static fn ($template, $data, $response) => $response);

        $response = $controller->$section($repository, new NullLogger());
        self::assertSame(503, $response->getStatusCode());
        $this->assertPrivateResponse($response);
    }

    /** @dataProvider sections */
    public function testMissingSessionCannotQueryStatistics(string $section): void
    {
        $repository = $this->createMock(VisitRepository::class);
        $repository->expects(self::never())->method('getAuthorStatsSummary');
        $repository->expects(self::never())->method('getAuthorStatsChart');
        $repository->expects(self::never())->method('getMostVisitedArticlesForNpub');
        $controller = $this->getMockBuilder(ProfileStatsController::class)
            ->onlyMethods(['render', 'getUser'])->getMock();
        $controller->method('getUser')->willReturn(null);
        $controller->expects(self::never())->method('render');
        $this->expectException(AccessDeniedException::class);

        $controller->$section($repository, new NullLogger());
    }

    public static function sections(): iterable
    {
        yield ['week'];
        yield ['month'];
        yield ['chart'];
    }

    private function controllerForUser(): ProfileStatsController
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn('npub-owner');
        $controller = $this->getMockBuilder(ProfileStatsController::class)
            ->onlyMethods(['render', 'getUser'])->getMock();
        $controller->method('getUser')->willReturn($user);
        return $controller;
    }

    private function assertPrivateResponse(Response $response): void
    {
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
}
