<?php

declare(strict_types=1);

namespace App\Security;

use DecentNewsroom\IdentityBundle\Security\Authenticator\NostrAuthenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly NostrAuthenticator $authenticator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (str_contains($request->headers->get('Accept', ''), 'application/json')
            || str_contains($request->headers->get('Content-Type', ''), 'application/json')
            || $request->isXmlHttpRequest()) {
            return $this->authenticator->start($request, $authException);
        }

        return new RedirectResponse($this->urls->generate('app_login'));
    }
}
