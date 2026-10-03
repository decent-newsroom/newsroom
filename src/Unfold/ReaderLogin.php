<?php

declare(strict_types=1);

namespace App\Unfold;

use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use nostriphant\NIP19\Bech32;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final readonly class ReaderLogin
{
    public function __construct(
        private SiteRegistryInterface $sites,
        #[Autowire('%base_domain%')] private string $baseDomain,
        #[Autowire('%env(default::SESSION_COOKIE_DOMAIN)%')] private ?string $sessionCookieDomain = null,
    ) {}

    public function loginUrl(Request $request, string $contentPath): string
    {
        $return = $request->getSchemeAndHttpHost() . $contentPath;
        if ($this->validateReturnUrl($return, $request) === null) {
            throw new \InvalidArgumentException('Invalid publication reader continuation.');
        }
        $sharedCookie = ltrim(strtolower($this->sessionCookieDomain ?? ''), '.') === strtolower($this->baseDomain)
            && str_contains($this->baseDomain, '.') && !str_ends_with($this->baseDomain, '.localhost');
        $port = $request->getPort();
        $authority = $sharedCookie
            ? $this->baseDomain . (in_array($port, [80, 443], true) ? '' : ':' . $port)
            : $request->getHttpHost();

        return $request->getScheme() . '://' . $authority . '/login?' . http_build_query(['unfold_reader_return' => $return]);
    }

    public function validateReturnUrl(string $url, Request $request): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\\\\]/', $url)
            || $parts['scheme'] !== $request->getScheme()
            || ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== $request->getPort()) {
            return null;
        }
        $suffix = '.' . $this->baseDomain;
        if (!str_ends_with($parts['host'], $suffix)) {
            return null;
        }
        $subdomain = substr($parts['host'], 0, -strlen($suffix));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain)
            || $this->sites->findBySubdomain($subdomain) === null
            || !preg_match('#^/(npub1[a-z0-9]+)/(a|chapter|wiki|spec)/([^/]+)$#D', $parts['path'], $matches)) {
            return null;
        }
        $identifier = rawurldecode($matches[3]);
        if ($identifier === '' || preg_match('/[\x00-\x1f\x7f]/', $identifier) || preg_match('//u', $identifier) !== 1) {
            return null;
        }
        try {
            if ((new Bech32($matches[1]))->type !== 'npub') {
                return null;
            }
        } catch (\Exception | \TypeError) {
            return null;
        }
        return $url;
    }
}
