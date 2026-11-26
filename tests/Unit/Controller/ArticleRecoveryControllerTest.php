<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Editor\ArticleRecoveryController;
use App\Entity\ArticleRecovery;
use App\Service\Nostr\NostrKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ArticleRecoveryControllerTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    public function testControllerRequiresAuthentication(): void
    {
        $attributes = (new \ReflectionClass(ArticleRecoveryController::class))->getAttributes(IsGranted::class);
        self::assertSame('ROLE_USER', $attributes[0]->newInstance()->attribute);
    }

    public function testListOnlyQueriesCurrentOwnerAndIsNotCacheable(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findBy')
            ->with(['pubkey' => self::OWNER], ['updatedAt' => 'DESC'])
            ->willReturn([new ArticleRecovery(self::ID, self::OWNER, $this->snapshot())]);
        $response = $this->controller()->list($this->manager($repository), new NostrKeyService());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame(self::ID, json_decode((string) $response->getContent(), true)[0]['id']);
    }

    public function testCannotOverwriteAnotherOwnersCopy(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn(new ArticleRecovery(self::ID, self::OTHER, $this->snapshot()));
        $repository->expects(self::once())->method('findOneBy')->with(['id' => self::ID, 'pubkey' => self::OWNER])->willReturn(null);
        $manager = $this->manager($repository);
        $manager->expects(self::never())->method('flush');
        $response = $this->controller()->save(self::ID, $this->request($this->snapshot()), $manager, new NostrKeyService());
        self::assertSame(404, $response->getStatusCode());
    }

    public function testCannotDeleteAnotherOwnersCopy(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findOneBy')->with(['id' => self::ID, 'pubkey' => self::OWNER])->willReturn(null);
        $manager = $this->manager($repository);
        $manager->expects(self::never())->method('remove');
        $response = $this->controller()->delete(self::ID, $this->request([]), $manager, new NostrKeyService());
        self::assertSame(404, $response->getStatusCode());
    }

    public function testInvalidCsrfAndInvalidOrOversizedPayloadsAreRejected(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $manager = $this->manager($repository);
        $manager->expects(self::never())->method('persist');
        $keys = new NostrKeyService();
        self::assertSame(403, $this->controller(false)->save(self::ID, $this->request($this->snapshot()), $manager, $keys)->getStatusCode());
        self::assertSame(400, $this->controller()->save(self::ID, $this->request([]), $manager, $keys)->getStatusCode());
        $large = new Request(content: str_repeat('x', 2 * 1024 * 1024 + 1));
        self::assertSame(413, $this->controller()->save(self::ID, $large, $manager, $keys)->getStatusCode());
    }

    public function testCreatesPrivateUnsignedSnapshot(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn(null);
        $manager = $this->manager($repository);
        $manager->expects(self::once())->method('persist')->with(self::callback(
            fn (ArticleRecovery $copy): bool => $copy->getId() === self::ID && $copy->getSnapshot() === $this->snapshot(),
        ));
        $manager->expects(self::once())->method('flush');
        $response = $this->controller()->save(self::ID, $this->request($this->snapshot()), $manager, new NostrKeyService());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testUpdatesAndDeletesOnlyTheOwnersCopy(): void
    {
        $copy = new ArticleRecovery(self::ID, self::OWNER, ['version' => 1, 'fields' => [], 'editorState' => []]);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($copy);
        $repository->expects(self::exactly(2))->method('findOneBy')
            ->with(['id' => self::ID, 'pubkey' => self::OWNER])->willReturn($copy);
        $manager = $this->manager($repository);
        $manager->expects(self::never())->method('persist');
        $manager->expects(self::once())->method('remove')->with($copy);
        $manager->expects(self::exactly(2))->method('flush');
        $controller = $this->controller();
        $keys = new NostrKeyService();
        self::assertSame(200, $controller->save(self::ID, $this->request($this->snapshot()), $manager, $keys)->getStatusCode());
        self::assertSame($this->snapshot(), $copy->getSnapshot());
        self::assertSame(200, $controller->delete(self::ID, $this->request([]), $manager, $keys)->getStatusCode());
    }

    private function controller(bool $csrfValid = true): ArticleRecoveryController
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser(self::OWNER, null, ['ROLE_USER']), 'main', ['ROLE_USER']));
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $container->set('security.csrf.token_manager', $csrf);
        $controller = new ArticleRecoveryController();
        $controller->setContainer($container);
        return $controller;
    }

    private function manager(EntityRepository $repository): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(ArticleRecovery::class)->willReturn($repository);
        return $manager;
    }

    private function request(array $snapshot): Request
    {
        return new Request(server: ['HTTP_X_CSRF_TOKEN' => 'token'], content: json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function snapshot(): array
    {
        return ['version' => 1, 'fields' => ['editor[title]' => ['value' => 'Test']], 'editorState' => ['content_NMD' => 'body']];
    }
}
