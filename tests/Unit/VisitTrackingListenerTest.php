<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Visit;
use App\EventListener\VisitTrackingListener;
use App\Repository\VisitRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class VisitTrackingListenerTest extends TestCase
{
    public function testTracksApiRoutes(): void
    {
        $capturedVisit = null;

        $visitRepository = $this->createMock(VisitRepository::class);
        $visitRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Visit $visit) use (&$capturedVisit): bool {
                $capturedVisit = $visit;

                return true;
            }));

        $listener = new VisitTrackingListener($visitRepository);
        $request = Request::create('/api/article/publish');

        $listener->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertInstanceOf(Visit::class, $capturedVisit);
        self::assertSame('/api/article/publish', $capturedVisit->getRoute());
    }

    public function testTracksNonApiRoutesAndCapturesReferer(): void
    {
        $capturedVisit = null;

        $visitRepository = $this->createMock(VisitRepository::class);
        $visitRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Visit $visit) use (&$capturedVisit): bool {
                $capturedVisit = $visit;

                return true;
            }));

        $listener = new VisitTrackingListener($visitRepository);
        $request = Request::create('/discover');
        $request->headers->set('referer', 'https://example.com/from-newsletter');

        $listener->onKernelRequest(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        ));

        self::assertInstanceOf(Visit::class, $capturedVisit);
        self::assertSame('/discover', $capturedVisit->getRoute());
        self::assertSame('https://example.com/from-newsletter', $capturedVisit->getReferer());
        self::assertNotNull($capturedVisit->getSessionId());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $capturedVisit->getSessionId());
    }
}


