<?php

namespace App\Controller\Editor;

use App\Util\CommonMark\Converter;
use League\CommonMark\Exception\CommonMarkException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class MarkdownController extends AbstractController
{
    /**
     * Process markdown preview request
     */
    #[Route('/editor/markdown/preview', name: 'editor_markdown_preview', methods: ['POST'])]
    public function preview(Request $request, Converter $converter): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $markdown = $data['markdown'] ?? '';
        try {
            $html = $converter->convertToHtml($markdown);
            return new JsonResponse(['html' => $html]);
        } catch (CommonMarkException $e) {
            return new JsonResponse(['error' => 'Failed to convert markdown: ' . $e->getMessage()], 400);
        }
    }
}

