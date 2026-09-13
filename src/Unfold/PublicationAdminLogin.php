<?php

declare(strict_types=1);

namespace App\Unfold;

use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final readonly class PublicationAdminLogin
{
    public function __construct(
        private SiteRegistryInterface $sites,
        #[Autowire('%base_domain%')] private string $baseDomain,
        #[Autowire('%env(default::SESSION_COOKIE_DOMAIN)%')] private ?string $sessionCookieDomain = null,
    ) {}

    public function loginUrl(Request $request): string
    {
        $port = $request->getPort();
        $authority = $this->baseDomain . (in_array($port, [80, 443], true) ? '' : ':' . $port);
        return $request->getScheme() . '://' . $authority . '/login?' . http_build_query([
            'unfold_return' => $request->getSchemeAndHttpHost() . $request->getPathInfo(),
        ]);
    }

    /**
     * Keep authentication on the main domain when cross-host cookies cannot work.
     * A repeated rejected cross-host return also falls back instead of looping.
     */
    public function resolveReturnUrl(string $url, Request $request, UserInterface $user): ?string
    {
        $destination = $this->validateReturnUrl($url, $request);
        if ($destination === null || parse_url($destination, PHP_URL_HOST) === $this->baseDomain) {
            return $destination;
        }

        $host = (string) parse_url($destination, PHP_URL_HOST);
        $subdomain = substr($host, 0, -strlen('.' . $this->baseDomain));
        $site = $this->sites->findBySubdomain($subdomain);
        if ($site === null) {
            return null;
        }
        try {
            [, $owner, $dtag] = explode(':', PublicationSettings::normalizeCoordinate($site->coordinate), 3);
        } catch (\InvalidArgumentException $e) {
            throw new ServiceUnavailableHttpException(null, 'Publication hosting needs operator repair.', $e);
        }
        try {
            $pubkey = PublicKey::fromBech32(strtolower($user->getUserIdentifier()))?->toHex();
        } catch (\Throwable $e) {
            throw new AccessDeniedHttpException(previous: $e);
        }
        if ($pubkey === null || $pubkey !== $owner) {
            throw new AccessDeniedHttpException();
        }

        $cookieDomain = ltrim(strtolower($this->sessionCookieDomain ?? ''), '.');
        $canShare = $cookieDomain === strtolower($this->baseDomain)
            && str_contains($this->baseDomain, '.')
            && !str_ends_with($this->baseDomain, '.localhost')
            && filter_var($this->baseDomain, FILTER_VALIDATE_IP) === false;

        $session = $request->hasSession() ? $request->getSession() : null;
        $attempt = $session?->get('unfold_admin_return_attempt');
        $repeated = is_array($attempt) && ($attempt['url'] ?? null) === $destination
            && is_int($attempt['time'] ?? null) && $attempt['time'] >= time() - 60;
        if ($canShare && $session !== null && !$repeated) {
            $session->set('unfold_admin_return_attempt', ['url' => $destination, 'time' => time()]);
            return $destination;
        }

        // The fallback must still identify the requested owner's publication.
        if (in_array($dtag, ['.', '..'], true) || preg_match('/[\x00-\x1f\/\\\\]/', $dtag)) {
            throw new ServiceUnavailableHttpException(null, 'Publication identifier cannot be used on the main-domain mount.');
        }
        $port = $request->getPort();
        $authority = $this->baseDomain . (in_array($port, [80, 443], true) ? '' : ':' . $port);
        return $request->getScheme() . '://' . $authority . '/mag/' . rawurlencode($dtag)
            . parse_url($destination, PHP_URL_PATH);
    }

    /** Only an admin destination on this installation can be used after login. */
    public function validateReturnUrl(string $url, Request $request): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || $parts['scheme'] !== $request->getScheme()
            || ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== $request->getPort()
            || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return null;
        }
        $host = $parts['host'];
        $path = $parts['path'];
        if ($host === $this->baseDomain) {
            if (!preg_match('#^/mag/([^/]+)/admin(?:/settings)?$#D', $path, $matches)
                || in_array(rawurldecode($matches[1]), ['.', '..'], true)
                || preg_match('/[\x00-\x20\/\\\\]/', rawurldecode($matches[1]))) {
                return null;
            }
            return $url;
        }
        $suffix = '.' . $this->baseDomain;
        if (!str_ends_with($host, $suffix) || !preg_match('#^/admin(?:/settings)?$#D', $path)) {
            return null;
        }
        $subdomain = substr($host, 0, -strlen($suffix));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain)) {
            return null;
        }
        return $this->sites->findBySubdomain($subdomain) === null ? null : $url;
    }
}
