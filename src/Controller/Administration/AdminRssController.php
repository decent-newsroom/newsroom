<?php

namespace App\Controller\Administration;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\RSS\RssFeedService;
use App\Service\RSS\RssToNostrConverter;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdminRssController extends AbstractController
{
    #[Route('/admin/rss/submit', name: 'admin_rss_submit', methods: ['GET', 'POST'])]
    public function submitRssFeed(Request $request, RssFeedService $rssFeedService, EntityManagerInterface $entityManager): Response
    {
        // Fetch magazines (Event entities) with type=magazine and owned by the user
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException('You must be logged in to access this page.');
        }

        $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());

        $magazines = $entityManager->getRepository(Event::class)->findBy([
            'kind' => KindsEnum::PUBLICATION_INDEX,
            'pubkey' => $pubkey,
        ]);
        $filteredMagazines = array_filter($magazines, function ($mag) {
            $tags = $mag->getTags();
            $isMagType = false;
            $isTopLevel = false;
            $hasSource = false;
            foreach ($tags as $tag) {
                if ($tag[0] === 'type' && $tag[1] === 'magazine') {
                    $isMagType = true;
                }
                if ($tag[0] === 'a' && $isTopLevel === false) {
                    $parts = explode(':', $tag[1]);
                    if ($parts[0] == (string)KindsEnum::PUBLICATION_INDEX->value) {
                        $isTopLevel = true;
                    }
                }
                if ($tag[0] === 'source' && !empty($tag[1])) {
                    $hasSource = true;
                }
            }
            // Optionally, filter by user ownership if needed
            return $isMagType && $isTopLevel && $hasSource;
        });

        $form = $this->createFormBuilder()
            ->add('feedUrl', TextType::class, [
                'label' => 'RSS Feed URL',
                'required' => true,
            ])
            ->add('magazine', TextType::class, [
                'label' => 'Magazine (optional)',
                'required' => false,
            ])
            ->getForm();

        $form->handleRequest($request);
        $articles = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $feedUrl = $form->get('feedUrl')->getData();
            $magazineSlug = $form->get('magazine')->getData();
            $feed = $rssFeedService->fetchFeed($feedUrl);
            $articles = $feed['items'] ?? [];
            $request->getSession()->set('rss_articles', $articles);
            $request->getSession()->set('selected_magazine', $magazineSlug);
            return $this->redirectToRoute('admin_rss_review');
        }

        return $this->render('admin/rss_submit.html.twig', [
            'form' => $form->createView(),
            'magazines' => $filteredMagazines,
        ]);
    }

    #[Route('/admin/rss/review', name: 'admin_rss_review', methods: ['GET', 'POST'])]
    public function reviewRssArticles(Request $request, RssToNostrConverter $converter): Response
    {
        $articles = $request->getSession()->get('rss_articles', []);
        $drafts = [];
        foreach ($articles as $item) {
            $drafts[] = $converter->convertToNostrEvent($item);
        }

        if ($request->isMethod('POST')) {
            $toSign = $request->request->all('sign');
            $signed = [];
            foreach ($toSign as $idx) {
                if (isset($drafts[$idx])) {
                    // Here, you would sign and persist the article
                    // For now, just collect for confirmation
                    $signed[] = $drafts[$idx];
                }
            }
            // TODO: Persist signed articles as needed
            $this->addFlash('success', count($signed) . ' articles signed and published.');
            $request->getSession()->remove('rss_articles');
            return $this->redirectToRoute('admin_rss_submit');
        }

        return $this->render('admin/rss_review.html.twig', [
            'drafts' => $drafts,
        ]);
    }

    // Add a route for signing and persisting selected articles as needed
}
