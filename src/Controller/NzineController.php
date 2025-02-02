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
     * @throws \JsonException
     */
    #[Route('/nzine', name: 'nzine_index')]
    public function index(Request $request, NzineWorkflowService $nzineWorkflowService): Response
    {
        $form = $this->createForm(NzineBotType::class);
        $form->handleRequest($request);
        $user = $this->getUser();

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            // init object
            $nzine = $nzineWorkflowService->init();
            // create bot and nzine, save to persistence
            $nzine = $nzineWorkflowService->createProfile($nzine, $data['name'], $data['about'], $user);
            // create main index
            $nzineWorkflowService->createMainIndex($nzine, $data['name'], $data['about']);

            return $this->redirectToRoute('nzine_edit', ['npub' => $nzine->getNpub() ]);
        }

        return $this->render('pages/nzine-editor.html.twig', [
            'form' => $form
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
        if (empty($mainIndex)) {
            throw $this->createNotFoundException('Main index not found');
        }

        $catForm = $this->createForm(NzineType::class, ['categories' => $nzine->getMainCategories()]);
        $catForm->handleRequest($request);
        if ($catForm->isSubmitted() && $catForm->isValid()) {
            // Process and normalize the 'tags' field
            $data = $catForm->get('categories')->getData();

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

            // TODO create and update indices
            foreach ($data as $cat) {
                // find or create new index
                $slugger = new AsciiSlugger();
                $title = $cat['title'];
                $slug = $mainIndex->getSlug().'-'.$slugger->slug($title)->lower();
                // create category index
                $index = new Event();
                $index->setKind(KindsEnum::PUBLICATION_INDEX->value);

                $index->addTag(['d' => $slug]);
                $index->addTag(['title' => $title]);
                $index->addTag(['auto-update' => 'yes']);
                $index->addTag(['type' => 'magazine']);
                // TODO add indexed items that fall into the category

                $signer = new Sign();
                // TODO get key

                $private_key = $encryptionService->decrypt($nzine->getNsec());
                $signer->signEvent($index, $private_key);
                // save to persistence, first map to EventEntity
                $serializer = new Serializer([new ObjectNormalizer()],[new JsonEncoder()]);
                $i = $serializer->deserialize($index->toJson(), EventEntity::class, 'json');
                // don't save any more for now
                $entityManager->persist($i);
                // $entityManager->flush();
                // TODO publish index to relays
            }

            // TODO add the new and updated indices to the main index


            // redirect to route nzine_view
            return $this->redirectToRoute('nzine_view', [
                'npub' => $nzine->getNpub(),
            ]);
        }

        return $this->render('pages/nzine-editor.html.twig', [
            'nzine' => $nzine,
            'indices' => $indices,
            'bot' => $bot,
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
        $main = $indices[0];

        return $this->render('pages/nzine.html.twig', [
            'nzine' => $nzine,
            'index' => $main
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

}
