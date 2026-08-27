<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ReactionController;
use App\Enum\KindsEnum;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class ReactionControllerTest extends TestCase
{
    public function testCurrentCountsPlusAndEmptyContentLikes(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':article';
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('3');

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                self::callback(static fn(string $sql): bool => str_contains($sql, 'e.content IN (:likeContent, :emptyLikeContent)')),
                self::callback(static fn(array $params): bool => $params['kind'] === KindsEnum::REACTION->value
                    && $params['likeContent'] === '+'
                    && $params['emptyLikeContent'] === ''
                    && isset($params['aTag'], $params['upperATag']))
            )
            ->willReturn($result);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());

        $controller = new ReactionController($this->createMock(LoggerInterface::class));
        $controller->setContainer($container);

        $response = $controller->current(new Request(['coordinate' => $coordinate]), $entityManager);
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, $payload['count']);
        self::assertFalse($payload['liked']);
    }
}
