<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Controller\Administration\UnfoldSiteController;
use App\Entity\UnfoldSite;
use App\Repository\UnfoldSiteRepository;
use App\Unfold\UnfoldSetupService;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UnfoldSetupControllerTest extends TestCase
{
    public function testCreateRejectsMissingCsrfBeforeSetup(): void
    {
        $setup = $this->createMock(UnfoldSetupService::class);
        $setup->expects(self::never())->method('create');
        $controller = $this->controller($setup);
        $controller->method('isCsrfTokenValid')->willReturn(false);
        $controller->expects(self::once())->method('redirectToRoute')->with('admin_unfold_new')->willReturn(new RedirectResponse('/new'));

        self::assertSame(302, $controller->create(Request::create('/new', 'POST'))->getStatusCode());
    }

    public function testCreateSavesLocallyWithoutSignedEvent(): void
    {
        $coordinate = '30040:' . str_repeat('a', 64) . ':root';
        $setup = $this->createMock(UnfoldSetupService::class);
        $setup->expects(self::once())->method('create')->with('publication', $coordinate, 'default')->willReturn(
            (new UnfoldSite())->setSubdomain('publication')->setCoordinate($coordinate),
        );
        $controller = $this->controller($setup);
        $controller->expects(self::once())->method('isCsrfTokenValid')->with('admin_unfold_create', 'valid')->willReturn(true);
        $controller->expects(self::once())->method('redirectToRoute')->with('admin_unfold_index')->willReturn(new RedirectResponse('/admin/unfold'));

        $response = $controller->create(Request::create('/new', 'POST', [
            '_token' => 'valid', 'subdomain' => 'publication', 'coordinate' => $coordinate, 'theme' => 'default',
        ]));
        self::assertSame('/admin/unfold', $response->headers->get('Location'));
    }

    public function testEditRejectsInvalidCsrfBeforeMutation(): void
    {
        $site = (new UnfoldSite())->setSubdomain('publication')->setCoordinate('30040:' . str_repeat('a', 64) . ':root');
        $repository = $this->createMock(UnfoldSiteRepository::class);
        $repository->method('find')->with(42)->willReturn($site);
        $setup = $this->createMock(UnfoldSetupService::class);
        $setup->expects(self::never())->method('update');
        $controller = $this->controller($setup, $repository);
        $controller->expects(self::once())->method('isCsrfTokenValid')->with('admin_unfold_edit42', null)->willReturn(false);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/edit'));

        $controller->edit(Request::create('/edit', 'POST', ['subdomain' => 'changed']), 42);
        self::assertSame('publication', $site->getSubdomain());
    }

    public function testEditDoesNotAcceptAReplacementRoot(): void
    {
        $coordinate = '30040:' . str_repeat('a', 64) . ':root';
        $site = (new UnfoldSite())->setSubdomain('publication')->setCoordinate($coordinate);
        $repository = $this->createMock(UnfoldSiteRepository::class);
        $repository->method('find')->with(42)->willReturn($site);
        $setup = $this->createMock(UnfoldSetupService::class);
        $setup->expects(self::once())->method('update')->with($site, 'renamed', 'default');
        $controller = $this->controller($setup, $repository);
        $controller->method('isCsrfTokenValid')->willReturn(true);
        $controller->method('redirectToRoute')->willReturn(new RedirectResponse('/admin/unfold'));

        $controller->edit(Request::create('/edit', 'POST', [
            '_token' => 'valid', 'subdomain' => 'renamed', 'theme' => 'default',
            'coordinate' => '30040:' . str_repeat('b', 64) . ':other-root',
        ]), 42);
        self::assertSame($coordinate, $site->getCoordinate());
    }

    private function controller(UnfoldSetupService $setup, ?UnfoldSiteRepository $repository = null): UnfoldSiteController
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $this->getMockBuilder(UnfoldSiteController::class)
            ->setConstructorArgs([
                $repository ?? $this->createMock(UnfoldSiteRepository::class),
                $this->createMock(EntityManagerInterface::class),
                $setup,
                $this->createMock(HandlebarsRenderer::class),
                $translator,
                new NullLogger(),
            ])
            ->onlyMethods(['isCsrfTokenValid', 'addFlash', 'redirectToRoute'])
            ->getMock();
    }
}
