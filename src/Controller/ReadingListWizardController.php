<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CategoryDraft;
use App\Form\CategoryArticlesType;
use App\Form\CategoryType;
use App\Service\ReadingListManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReadingListWizardController extends AbstractController
{
    private const SESSION_KEY = 'read_wizard';

    #[Route('/reading-list/wizard/setup', name: 'read_wizard_setup')]
    public function setup(Request $request): Response
    {
        $draft = $this->getDraft($request) ?? new CategoryDraft();

        $form = $this->createForm(CategoryType::class, $draft);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CategoryDraft $draft */
            $draft = $form->getData();
            if (!$draft->slug) {
                $draft->slug = $this->slugifyWithRandom($draft->title);
            }
            $this->saveDraft($request, $draft);
            return $this->redirectToRoute('read_wizard_articles');
        }

        return $this->render('reading_list/reading_setup.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/reading-list/wizard/articles', name: 'read_wizard_articles')]
    public function articles(Request $request): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            return $this->redirectToRoute('read_wizard_setup');
        }

        // Ensure at least one input is visible initially
        if (empty($draft->articles)) {
            $draft->articles = [''];
        }

        $form = $this->createForm(CategoryArticlesType::class, $draft);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CategoryDraft $draft */
            $draft = $form->getData();
            // ensure slug exists
            if (!$draft->slug) {
                $draft->slug = $this->slugifyWithRandom($draft->title);
            }
            $this->saveDraft($request, $draft);
            return $this->redirectToRoute('read_wizard_review');
        }

        return $this->render('reading_list/reading_articles.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/reading-list/add-article', name: 'read_wizard_add_article')]
    public function addArticle(Request $request, ReadingListManager $readingListManager): Response
    {
        // Get the coordinate from the query parameter
        $coordinate = $request->query->get('coordinate');

        if (!$coordinate) {
            $this->addFlash('error', 'No article coordinate provided.');
            return $this->redirectToRoute('reading_list_compose');
        }

        // Get available reading lists
        $availableLists = $readingListManager->getUserReadingLists();
        $currentDraft = $readingListManager->getCurrentDraft();

        // Handle form submission
        if ($request->isMethod('POST')) {
            $selectedSlug = $request->request->get('selected_list');

            // Load or create the selected list
            if ($selectedSlug === '__new__' || !$selectedSlug) {
                $draft = $readingListManager->createNewDraft();
            } else {
                $draft = $readingListManager->loadPublishedListIntoDraft($selectedSlug);
            }

            // Add the article to the draft
            if (!in_array($coordinate, $draft->articles, true)) {
                $draft->articles[] = $coordinate;
                $session = $request->getSession();
                $session->set('read_wizard', $draft);
            }

            // Redirect to compose page with success message
            return $this->redirectToRoute('reading_list_compose', [
                'add' => $coordinate,
                'list' => $selectedSlug ?? '__new__'
            ]);
        }

        return $this->render('reading_list/add_article_confirm.html.twig', [
            'coordinate' => $coordinate,
            'availableLists' => $availableLists,
            'currentDraft' => $currentDraft,
        ]);
    }

    #[Route('/reading-list/wizard/review', name: 'read_wizard_review')]
    public function review(Request $request): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            return $this->redirectToRoute('read_wizard_setup');
        }

        // Build a single category event skeleton
        $tags = [];
        $tags[] = ['d', $draft->slug];
        $tags[] = ['type', 'reading-list'];
        if ($draft->title) { $tags[] = ['title', $draft->title]; }
        if ($draft->summary) { $tags[] = ['summary', $draft->summary]; }
        foreach ($draft->tags as $t) { $tags[] = ['t', $t]; }
        foreach ($draft->articles as $a) {
            if (is_string($a) && $a !== '') { $tags[] = ['a', $a]; }
        }

        $event = [
            'kind' => 30040,
            'created_at' => time(),
            'tags' => $tags,
            'content' => '',
        ];

        return $this->render('reading_list/reading_review.html.twig', [
            'draft' => $draft,
            'eventJson' => json_encode($event, JSON_UNESCAPED_SLASHES),
            'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('nostr_publish')->getValue(),
        ]);
    }

    #[Route('/reading-list/wizard/cancel', name: 'read_wizard_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        $this->clearDraft($request);
        $this->addFlash('info', 'Reading list creation canceled.');
        return $this->redirectToRoute('home');
    }

    private function getDraft(Request $request): ?CategoryDraft
    {
        $data = $request->getSession()->get(self::SESSION_KEY);
        return $data instanceof CategoryDraft ? $data : null;
    }

    private function saveDraft(Request $request, CategoryDraft $draft): void
    {
        $request->getSession()->set(self::SESSION_KEY, $draft);
    }

    private function clearDraft(Request $request): void
    {
        $request->getSession()->remove(self::SESSION_KEY);
    }

    private function slugifyWithRandom(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) ?? '');
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
        $rand = substr(bin2hex(random_bytes(4)), 0, 6);
        return $slug !== '' ? ($slug . '-' . $rand) : $rand;
    }
}
