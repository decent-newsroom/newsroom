<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Event as EventEntity;
use App\Entity\Nzine;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Form\NzineBotType;
use App\Form\NzineType;
use App\Service\EncryptionService;
use App\Service\NostrClient;
use App\Service\NzineWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\String\Slugger\AsciiSlugger;

class NzineController extends AbstractController
{
    /**
     * List all NZines owned by the current user
     */
    #[Route('/my-nzines', name: 'nzine_list')]
    public function list(EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $nzines = [];

        if ($user) {
            $userIdentifier = $user->getUserIdentifier();

            // Find all nzines where the current user is the editor
            $allNzines = $entityManager->getRepository(Nzine::class)
                ->findBy(['editor' => $userIdentifier], ['id' => 'DESC']);

            foreach ($allNzines as $nzine) {
                // Get the feed config for title and summary
                $feedConfig = $nzine->getFeedConfig();
                $title = $feedConfig['title'] ?? 'Untitled NZine';
                $summary = $feedConfig['summary'] ?? null;

                // Count categories
                $categoryCount = count($nzine->getMainCategories());

                // Get main index to check publication status
                $mainIndex = $entityManager->getRepository(EventEntity::class)
                    ->findOneBy([
                        'pubkey' => $nzine->getNpub(),
                        'kind' => KindsEnum::PUBLICATION_INDEX->value,
                        // We'd need to filter by d-tag matching slug, but let's get first one for now
                    ]);

                $nzines[] = [
                    'id' => $nzine->getId(),
                    'npub' => $nzine->getNpub(),
                    'title' => $title,
                    'summary' => $summary,
                    'slug' => $nzine->getSlug(),
                    'state' => $nzine->getState(),
                    'categoryCount' => $categoryCount,
                    'hasMainIndex' => $mainIndex !== null,
                    'feedUrl' => $nzine->getFeedUrl(),
                ];
            }
        }

        return $this->render('nzine/list.html.twig', [
            'nzines' => $nzines,
        ]);
    }

    /**
     * @throws \JsonException
     */
    #[Route('/nzine', name: 'nzine_index')]
    public function index(Request $request, NzineWorkflowService $nzineWorkflowService, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $isAuthenticated = $user !== null;

        $form = $this->createForm(NzineBotType::class, null, [
            'disabled' => !$isAuthenticated
        ]);
        $form->handleRequest($request);

        $nzine = $entityManager->getRepository(Nzine::class)->findAll();


        if ($form->isSubmitted() && $form->isValid() && $isAuthenticated) {
            $data = $form->getData();
            // init object
            $nzine = $nzineWorkflowService->init();

            // Set RSS feed URL if provided
            if (!empty($data['feedUrl'])) {
                $nzine->setFeedUrl($data['feedUrl']);
            }

            // Store title and summary for later use when creating main index
            $nzine->setFeedConfig([
                'title' => $data['name'],
                'summary' => $data['about']
            ]);

            // create bot and nzine, save to persistence
            // Note: We don't create the main index yet - that happens after categories are configured
            $nzine = $nzineWorkflowService->createProfile($nzine, $data['name'], $data['about'], $user);

            return $this->redirectToRoute('nzine_edit', ['npub' => $nzine->getNpub() ]);
        }

        return $this->render('pages/nzine-editor.html.twig', [
            'form' => $form,
            'isAuthenticated' => $isAuthenticated
        ]);
    }

    #[Route('/nzine/{npub}', name: 'nzine_edit')]
    public function edit(Request $request, $npub, EntityManagerInterface $entityManager,
                         EncryptionService $encryptionService,
                         ManagerRegistry $managerRegistry, NostrClient $nostrClient): Response
    {
        $nzine = $entityManager->getRepository(Nzine::class)->findOneBy(['npub' => $npub]);
        if (!$nzine) {
            throw $this->createNotFoundException('N-Zine not found');
        }
        try {
            $bot = $entityManager->getRepository(User::class)->findOneBy(['npub' => $npub]);
        } catch (Exception $e) {
            // sth went wrong, but whatever
            $managerRegistry->resetManager();
        }

        // existing index
        $indices = $entityManager->getRepository(EventEntity::class)->findBy(['pubkey' => $npub, 'kind' => KindsEnum::PUBLICATION_INDEX]);

        $mainIndexCandidates = array_filter($indices, function ($index) use ($nzine) {
            return $index->getSlug() == $nzine->getSlug();
        });

        $mainIndex = array_pop($mainIndexCandidates);

        // If no main index exists yet, allow user to add categories but don't create indices yet
        $canCreateIndices = !empty($mainIndex);

        $catForm = $this->createForm(NzineType::class, ['categories' => $nzine->getMainCategories()]);
        $catForm->handleRequest($request);

        if ($catForm->isSubmitted() && $catForm->isValid()) {
            // Process and normalize the 'tags' field
            $data = $catForm->get('categories')->getData();

            // Auto-generate slugs if not provided
            $slugger = new AsciiSlugger();
            foreach ($data as &$cat) {
                if (empty($cat['slug']) && !empty($cat['title'])) {
                    $cat['slug'] = $slugger->slug($cat['title'])->lower()->toString();
                }
            }
            unset($cat); // break reference

            $nzine->setMainCategories($data);

            try {
                $entityManager->beginTransaction();
                $entityManager->persist($nzine);
                $entityManager->flush();
                $entityManager->commit();
            } catch (Exception $e) {
                $entityManager->rollback();
                $managerRegistry->resetManager();
            }

            // Only create category indices if main index exists
            if ($canCreateIndices) {
                $catIndices = [];

                $bot = $nzine->getNzineBot();
                $bot->setEncryptionService($encryptionService);
                $private_key = $bot->getNsec(); // decrypted en route

                foreach ($data as $cat) {
                    // Validate category has required fields
                    if (!isset($cat['title']) || empty($cat['title'])) {
                        continue; // Skip invalid categories
                    }

                    // check if such an index exists, only create new cats
                    $id = array_filter($indices, function ($k) use ($cat) {
                        return isset($cat['title']) && $cat['title'] === $k->getTitle();
                    });
                    if (!empty($id)) { continue; }

                    // create new index
                    // currently not possible to edit existing, because there is no way to tell what has changed
                    // and which is the corresponding event
                    $title = $cat['title'];
                    $slug = isset($cat['slug']) && !empty($cat['slug'])
                        ? $cat['slug']
                        : $slugger->slug($title)->lower()->toString();

                    // Use just the category slug for the d-tag so it can be found by the magazine frontend
                    // The main index will reference this via 'a' tags with full coordinates
                    $indexSlug = $slug;

                    // create category index
                    $index = new Event();
                    $index->setKind(KindsEnum::PUBLICATION_INDEX->value);

                    $index->addTag(['d', $indexSlug]);
                    $index->addTag(['title', $title]);
                    $index->addTag(['auto-update', 'yes']);
                    $index->addTag(['type', 'magazine']);

                    // Add tags for RSS matching
                    if (isset($cat['tags']) && is_array($cat['tags'])) {
                        foreach ($cat['tags'] as $tag) {
                            $index->addTag(['t', $tag]);
                        }
                    }
                    $index->setPublicKey($nzine->getNpub());

                    $signer = new Sign();
                    $signer->signEvent($index, $private_key);
                    // save to persistence, first map to EventEntity
                    $serializer = new Serializer([new ObjectNormalizer()],[new JsonEncoder()]);
                    $i = $serializer->deserialize($index->toJson(), EventEntity::class, 'json');
                    // don't save any more for now
                    $entityManager->persist($i);
                    $entityManager->flush();
                    // TODO publish index to relays

                    $catIndices[] = $index;
                }

                // add the new and updated indices to the main index
                foreach ($catIndices as $idx) {
                    //remove e tags and add new
                    // $tags = array_splice($mainIndex->getTags(), -3);
                    // $mainIndex->setTags($tags);
                    // TODO add relay hints
                    $mainIndex->addTag(['a', KindsEnum::PUBLICATION_INDEX->value .':'. $idx->getPublicKey() .':'. $idx->getSlug()]);
                    // $mainIndex->addTag(['e' => $idx->getId()]);
                }

                // re-sign main index and save to relays
                // $signer = new Sign();
                // $signer->signEvent($mainIndex, $private_key);
                // for now, just save new index
                $entityManager->flush();
            } else {
                // Categories saved but no indices created yet
                $this->addFlash('info', 'Categories saved. Indices will be created once the main index is published.');
            }

            // redirect to route nzine_view if main index exists, otherwise stay on edit page
            if ($canCreateIndices) {
                return $this->redirectToRoute('nzine_view', [
                    'npub' => $nzine->getNpub(),
                ]);
            } else {
                return $this->redirectToRoute('nzine_edit', [
                    'npub' => $nzine->getNpub(),
                ]);
            }
        }

        return $this->render('pages/nzine-editor.html.twig', [
            'nzine' => $nzine,
            'indices' => $indices,
            'mainIndex' => $mainIndex,
            'canCreateIndices' => $canCreateIndices,
            'bot' => $bot ?? null, // if null, the profile for the bot doesn't exist yet
            'catForm' => $catForm
        ]);
    }

    /**
     * Update and (re)publish indices,
     * when you want to look for new articles or
     * when categories have changed
     * @return void
     */
    #[Route('/nzine/{npub}', name: 'nzine_update')]
    public function nzineUpdate()
    {
        // TODO make this a separate step and publish all the indices and populate with articles all at once

    }


    #[Route('/nzine/v/{npub}', name: 'nzine_view')]
    public function nzineView($npub, EntityManagerInterface $entityManager): Response
    {
        $nzine = $entityManager->getRepository(Nzine::class)->findOneBy(['npub' => $npub]);
        if (!$nzine) {
            throw $this->createNotFoundException('N-Zine not found');
        }
        // Find all index events for this nzine
        $indices = $entityManager->getRepository(EventEntity::class)->findBy(['pubkey' => $npub, 'kind' => KindsEnum::PUBLICATION_INDEX]);
        $mainIndexCandidates = array_filter($indices, function ($index) use ($nzine) {
            return $index->getSlug() == $nzine->getSlug();
        });

        dump($indices);die();

        $mainIndex = array_pop($mainIndexCandidates);

        return $this->render('pages/nzine.html.twig', [
            'nzine' => $nzine,
            'index' => $mainIndex,
            'events' => $indices, // TODO traverse all and collect all leaves
        ]);
    }

    #[Route('/nzine/v/{npub}/{cat}', name: 'nzine_category')]
    public function nzineCategory($npub, $cat, EntityManagerInterface $entityManager): Response
    {
        $nzine = $entityManager->getRepository(Nzine::class)->findOneBy(['npub' => $npub]);
        if (!$nzine) {
            throw $this->createNotFoundException('N-Zine not found');
        }
        $bot = $entityManager->getRepository(User::class)->findOneBy(['npub' => $npub]);

        $tags = [];
        foreach ($nzine->getMainCategories() as $category) {
            if (isset($category['title']) && $category['title'] === $cat) {
                $tags = $category['tags'] ?? [];
            }
        }

        $all = $entityManager->getRepository(Article::class)->findAll();
        $list = array_slice($all, 0, 100);

        $filtered = [];
        foreach ($tags as $tag) {
            $partial = array_filter($list, function($v) use ($tag) {
                /* @var Article $v */
                return in_array($tag, $v->getTopics() ?? []);
            });
            $filtered = array_merge($filtered, $partial);
        }


        return $this->render('pages/nzine.html.twig', [
            'nzine' => $nzine,
            'bot' => $bot,
            'list' => $filtered
        ]);
    }

    #[Route('/nzine/{npub}/publish', name: 'nzine_publish', methods: ['POST'])]
    public function publish($npub, EntityManagerInterface $entityManager,
                           EncryptionService $encryptionService,
                           ManagerRegistry $managerRegistry): Response
    {
        $nzine = $entityManager->getRepository(Nzine::class)->findOneBy(['npub' => $npub]);
        if (!$nzine) {
            throw $this->createNotFoundException('N-Zine not found');
        }

        // Check if categories are configured
        if (empty($nzine->getMainCategories())) {
            $this->addFlash('error', 'Please add at least one category before publishing.');
            return $this->redirectToRoute('nzine_edit', ['npub' => $npub]);
        }

        // Check if main index already exists
        $indices = $entityManager->getRepository(EventEntity::class)->findBy(['pubkey' => $npub, 'kind' => KindsEnum::PUBLICATION_INDEX]);
        $mainIndexCandidates = array_filter($indices, function ($index) use ($nzine) {
            return $index->getSlug() == $nzine->getSlug();
        });

        if (!empty($mainIndexCandidates)) {
            $this->addFlash('warning', 'Main index already exists.');
            return $this->redirectToRoute('nzine_edit', ['npub' => $npub]);
        }

        try {
            // Start transaction
            $entityManager->beginTransaction();

            $bot = $nzine->getNzineBot();
            if (!$bot) {
                throw new \RuntimeException('Nzine bot not found');
            }

            $bot->setEncryptionService($encryptionService);
            $private_key = $bot->getNsec();

            if (!$private_key) {
                throw new \RuntimeException('Failed to decrypt bot private key');
            }

            // Get title and summary from feedConfig
            $config = $nzine->getFeedConfig();
            $title = $config['title'] ?? 'Untitled';
            $summary = $config['summary'] ?? '';

            // Generate slug for main index
            $slugger = new AsciiSlugger();
            $slug = 'nzine-'.$slugger->slug($title)->lower().'-'.rand(10000,99999);
            $nzine->setSlug($slug);

            // Create main index
            $mainIndex = new Event();
            $mainIndex->setKind(KindsEnum::PUBLICATION_INDEX->value);
            $mainIndex->addTag(['d', $slug]);
            $mainIndex->addTag(['title', $title]);
            $mainIndex->addTag(['summary', $summary]);
            $mainIndex->addTag(['auto-update', 'yes']);
            $mainIndex->addTag(['type', 'magazine']);
            $mainIndex->setPublicKey($nzine->getNpub());

            // Create category indices
            $catIndices = [];
            foreach ($nzine->getMainCategories() as $cat) {
                if (!isset($cat['title'])) {
                    continue; // Skip categories without titles
                }

                $catTitle = $cat['title'];
                $catSlug = $cat['slug'] ?? $slugger->slug($catTitle)->lower()->toString();
                // Use just the category slug for the d-tag so it can be found by the magazine frontend
                // The main index will reference this via 'a' tags with full coordinates
                $indexSlug = $catSlug;

                $catIndex = new Event();
                $catIndex->setKind(KindsEnum::PUBLICATION_INDEX->value);
                $catIndex->addTag(['d', $indexSlug]);
                $catIndex->addTag(['title', $catTitle]);
                $catIndex->addTag(['auto-update', 'yes']);
                $catIndex->addTag(['type', 'magazine']);

                // Add tags for RSS matching
                if (isset($cat['tags']) && is_array($cat['tags'])) {
                    foreach ($cat['tags'] as $tag) {
                        $catIndex->addTag(['t', $tag]);
                    }
                }
                $catIndex->setPublicKey($nzine->getNpub());

                // Sign category index
                $signer = new Sign();
                $signer->signEvent($catIndex, $private_key);

                // Save category index
                $serializer = new Serializer([new ObjectNormalizer()],[new JsonEncoder()]);
                $i = $serializer->deserialize($catIndex->toJson(), EventEntity::class, 'json');
                $entityManager->persist($i);

                // Add reference to main index
                $mainIndex->addTag(['a', KindsEnum::PUBLICATION_INDEX->value .':'. $catIndex->getPublicKey() .':'. $indexSlug]);

                $catIndices[] = $catIndex;
            }

            // Sign main index (after adding all category references)
            $signer = new Sign();
            $signer->signEvent($mainIndex, $private_key);

            // Save main index
            $serializer = new Serializer([new ObjectNormalizer()],[new JsonEncoder()]);
            $mainIndexEntity = $serializer->deserialize($mainIndex->toJson(), EventEntity::class, 'json');
            $entityManager->persist($mainIndexEntity);

            // Update nzine state
            $nzine->setState('published');
            $entityManager->persist($nzine);

            // Commit transaction
            $entityManager->flush();
            $entityManager->commit();

            $this->addFlash('success', sprintf(
                'N-Zine published successfully! Created main index and %d category indices.',
                count($catIndices)
            ));

            return $this->redirectToRoute('nzine_edit', ['npub' => $npub]);

        } catch (Exception $e) {
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->rollback();
            }
            $managerRegistry->resetManager();

            $this->addFlash('error', 'Failed to publish N-Zine: ' . $e->getMessage());

            // Log the full error for debugging
            error_log('N-Zine publish error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            return $this->redirectToRoute('nzine_edit', ['npub' => $npub]);
        }
    }

}
