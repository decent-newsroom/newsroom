<?php

declare(strict_types=1);

namespace App\Api\Books\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OptionsController
{
    #[Route('/api/{path}', name: 'options', methods: ['OPTIONS'], requirements: ['path' => '.+'])]
    public function options(): Response
    {
        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
