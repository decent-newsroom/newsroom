<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Controller;

use DecentNewsroom\SigningBundle\Service\Nostr\NostrConnectUriFactory;
use Endroid\QrCode\Exception\ValidationException;
use Random\RandomException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class NostrConnectController
{
    public const REQUESTED_PERMISSIONS = 'sign_event:27235,sign_event:22242,get_public_key';

    public function __construct(private NostrConnectUriFactory $uriFactory)
    {
    }

    /**
     * @throws RandomException
     * @throws ValidationException
     */
    #[Route('/nostr-connect/qr', name: 'nostr_connect_qr', methods: ['GET'])]
    public function qr(Request $request): JsonResponse
    {
        return new JsonResponse($this->uriFactory->create($request));
    }
}
