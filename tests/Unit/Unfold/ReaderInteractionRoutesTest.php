<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Controller\ReaderInteractionController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;

final class ReaderInteractionRoutesTest extends TestCase
{
    /** @dataProvider endpoints */
    public function testHostedInteractionsPrecedeCatchAll(string $suffix, string $method, string $action): void
    {
        $request = Request::create('https://publication.localhost/unfold/api/interactions' . $suffix, $method);
        $request->attributes->set('_unfold_site', new PublicationSite('publication', '30040:' . str_repeat('a', 64) . ':root'));
        $route = $this->matcher($request)->matchRequest($request);
        self::assertSame(ReaderInteractionController::class . '::' . $action, $route['_controller']);
    }

    public function testUnregisteredHostCannotMatchInteractionCollection(): void
    {
        $request = Request::create('https://unregistered.localhost/unfold/api/interactions/me');
        $this->expectException(ResourceNotFoundException::class);
        $this->matcher($request)->matchRequest($request);
    }

    public static function endpoints(): iterable
    {
        yield ['', 'GET', 'index'];
        yield ['/me', 'GET', 'me'];
        yield ['/prepare', 'POST', 'prepare'];
        yield ['/publish', 'POST', 'publish'];
        yield ['/status', 'GET', 'status'];
        yield ['/retry', 'POST', 'retry'];
    }

    private function matcher(Request $request): UrlMatcher
    {
        $loader = new YamlFileLoader(new FileLocator(dirname(__DIR__, 3) . '/packages/unfold-bundle/Resources/config'));
        $routes = $loader->load('routes.yaml');
        return new UrlMatcher($routes, (new RequestContext())->fromRequest($request));
    }
}
