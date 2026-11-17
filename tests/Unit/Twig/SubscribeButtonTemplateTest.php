<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class SubscribeButtonTemplateTest extends TestCase
{
    /**
     * @dataProvider buttonStates
     */
    public function testCompactAndDefaultButtonStates(bool $compact, bool $subscribed): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (string $name, array $attributes): string => sprintf(
            '<svg class="%s" aria-hidden="%s" data-icon="%s"></svg>',
            $attributes['class'],
            $attributes['aria-hidden'],
            $name,
        ), ['is_safe' => ['html']]));

        $html = $twig->render('components/Molecules/SubscribeButton.html.twig', [
            'attributes' => '',
            'error' => '',
            'compact' => $compact,
            'subscribed' => $subscribed,
        ]);

        self::assertStringContainsString('data-live-action-param="toggle"', $html);
        self::assertStringContainsString('aria-pressed="' . ($subscribed ? 'true' : 'false') . '"', $html);
        self::assertStringContainsString($subscribed ? 'updates.subscription.subscribed' : 'updates.subscription.addSubmit', $html);
        if ($compact) {
            self::assertStringContainsString('class="card-bookmark-btn' . ($subscribed ? ' is-active' : '') . '"', $html);
            self::assertStringContainsString('data-icon="iconoir:rss-feed"', $html);
            self::assertStringContainsString('aria-hidden="true"', $html);
        } else {
            self::assertStringContainsString($subscribed ? 'btn btn-outline-secondary' : 'btn btn-outline-primary', $html);
            self::assertStringNotContainsString('<svg', $html);
        }
    }

    public function buttonStates(): iterable
    {
        yield 'compact subscribe' => [true, false];
        yield 'compact subscribed' => [true, true];
        yield 'default subscribe' => [false, false];
        yield 'default subscribed' => [false, true];
    }
}
