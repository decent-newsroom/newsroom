<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Contract\PublicationAdminIdentityInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class PublicationAdminSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PublicationAdminIdentityInterface $identity,
        private HostPublicationResolver $hostResolver,
        private CoordinatePublicationResolver $coordinateResolver,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'resolve', KernelEvents::RESPONSE => ['makePrivate', -2048]];
    }

    public function resolve(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->getBoolean('_unfold_admin')) {
            return;
        }
        $pubkey = $this->identity->pubkey();
        if ($pubkey === null) {
            $url = $this->identity->loginUrl($request);
            $event->setController(static fn () => new RedirectResponse($url));
            return;
        }
        $resolver = $request->attributes->get('_unfold_mount') === PublicationMount::SUBDOMAIN->value
            ? $this->hostResolver : $this->coordinateResolver;
        $request->attributes->set('publication', $resolver->resolve($request, $pubkey));
    }

    public function makePrivate(ResponseEvent $event): void
    {
        if ($event->getRequest()->attributes->getBoolean('_unfold_admin')) {
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
        }
    }
}
