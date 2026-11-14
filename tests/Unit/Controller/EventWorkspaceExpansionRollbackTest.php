<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Bookshelf\BookshelfReferencesController;
use App\Controller\Newsroom\MyContentPreviewController;
use App\Controller\Reader\ReadingNookController;
use App\Service\Cache\RedisCacheService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class EventWorkspaceExpansionRollbackTest extends TestCase
{
    /**
     * No container, owner, database, traversal validation or rendering is needed
     * when rolled back. Even malformed paging/traversal requests must get 404.
     *
     * @dataProvider fragmentActions
     * @param class-string $controllerClass
     */
    public function testDisabledFragmentsFailClosedBeforeDoingWork(string $controllerClass, string $action): void
    {
        $controller = $controllerClass === ReadingNookController::class
            ? new ReadingNookController($this->createMock(RedisCacheService::class), false)
            : new $controllerClass(false);
        $method = new \ReflectionMethod($controllerClass, $action);
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            if ($type->getName() === 'string') {
                $arguments[] = str_repeat('a', 64);
            } elseif ($type->getName() === Request::class) {
                $arguments[] = Request::create('/fragment', 'GET', ['page' => ['invalid']]);
            } else {
                // Uninitialized dependencies ensure a regression cannot do work
                // unnoticed; the rollback check must be the first operation.
                /** @var class-string $dependencyClass */
                $dependencyClass = $type->getName();
                $class = new \ReflectionClass($dependencyClass);
                $arguments[] = $class->isInterface()
                    ? $this->createMock($dependencyClass)
                    : $class->newInstanceWithoutConstructor();
            }
        }

        $this->expectException(NotFoundHttpException::class);
        $method->invokeArgs($controller, $arguments);
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function fragmentActions(): iterable
    {
        yield 'members' => [ReadingNookController::class, 'followPackMembers'];
        yield 'bookmarks' => [ReadingNookController::class, 'bookmarkReferences'];
        yield 'root toc' => [ReadingNookController::class, 'publicationToc'];
        yield 'signed nested toc' => [ReadingNookController::class, 'nestedPublicationToc'];
        yield 'toc discovery and status' => [ReadingNookController::class, 'publicationTocDiscovery'];
        yield 'directory' => [BookshelfReferencesController::class, 'references'];
        yield 'article and draft' => [MyContentPreviewController::class, 'preview'];
    }
}
