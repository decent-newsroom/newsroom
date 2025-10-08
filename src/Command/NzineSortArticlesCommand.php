<?php

namespace App\Command;

use App\Entity\Article;
use App\Entity\Event as DbEvent; // your Doctrine entity
use App\Entity\NzineBot;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\NzineRepository;
use App\Service\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use swentel\nostr\Event\Event as WireEvent;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nzine:sort:articles',
    description: 'Update 30040 index events with matching 30023 articles based on tags',
)]
class NzineSortArticlesCommand extends Command
{
    public function __construct(
        private readonly NzineRepository        $nzineRepository,
        private readonly ArticleRepository      $articleRepository,
        private readonly EntityManagerInterface $em,
        private readonly EncryptionService      $encryptionService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $nzine = $this->nzineRepository->findOneBy([]);
        if (!$nzine) {
            $io->error('No NZine entity found.');
            return Command::FAILURE;
        }

        /** @var NzineBot $bot */
        $bot = $nzine->getNzineBot();
        $bot->setEncryptionService($this->encryptionService);

        $key = new Key();
        $signer = new Sign();
        $publicKey = strtolower($key->getPublicKey($bot->getNsec())); // hex

        /** @var Article[] $articles */
        $articles = $this->articleRepository->findBy(['pubkey' => $publicKey]);
        $io->writeln('Articles for bot: ' . count($articles));

        /** @var DbEvent[] $indexes */
        $indexes = $this->em->getRepository(DbEvent::class)->findBy([
            'pubkey' => $publicKey,
            'kind'   => KindsEnum::PUBLICATION_INDEX,
        ]);
        $io->writeln('Found ' . count($indexes) . ' existing indexes for bot ' . $publicKey);

        if (!$indexes) {
            $io->warning('No existing publication indexes found; nothing to update.');
            return Command::SUCCESS;
        }

        // newest index per d-tag (slug)
        $newestIndexBySlug = [];
        foreach ($indexes as $idx) {
            $d = $this->firstTagValue($idx->getTags() ?? [], 'd');
            if ($d === null) continue;
            if (!isset($newestIndexBySlug[$d]) || $idx->getCreatedAt() > $newestIndexBySlug[$d]->getCreatedAt()) {
                $newestIndexBySlug[$d] = $idx;
            }
        }

        $mainCategories = $nzine->getMainCategories() ?? [];
        $totalUpdated = 0;

        foreach ($mainCategories as $category) {
            $slug = (string)($category['slug'] ?? '');
            if ($slug === '') continue;

            $index = $newestIndexBySlug[$slug] ?? null;
            if (!$index) {
                $io->writeln(" - Skip category '{$slug}': no index found for this slug.");
                continue;
            }

            $tags = $index->getTags() ?? [];

            // topics tracked by this index (t-tags)
            $trackedTopics = array_values(array_unique(array_filter(array_map(
                fn($t) => $this->normTag($t),
                $this->allTagValues($tags, 't')
            ))));
            if (!$trackedTopics) {
                $io->writeln(" - Index d='{$slug}': no tracked 't' tags, skipping.");
                continue;
            }

            // existing a-tags for dedupe
            $existingA = [];
            foreach ($tags as $t) {
                if (($t[0] ?? null) === 'a' && isset($t[1])) {
                    $existingA[strtolower($t[1])] = true;
                }
            }

            $added = 0;

            foreach ($articles as $article) {
                if (strtolower($article->getPubkey()) !== $publicKey) continue;

                $slugArticle = (string)$article->getSlug();
                if ($slugArticle === '') continue;

                $articleTopics = $article->getTopics() ?? [];
                if (!$articleTopics) continue;

                if (!$this->intersects($articleTopics, $trackedTopics)) continue;

                $coord = sprintf('%s:%s:%s', KindsEnum::LONGFORM->value, $publicKey, $slugArticle);
                $coordKey = strtolower($coord);
                if (!isset($existingA[$coordKey])) {
                    $tags[] = ['a', $coord];
                    $existingA[$coordKey] = true;
                    $added++;
                }
            }

            if ($added > 0) {
                $tags = $this->sortedATagsLast($tags);
                $index->setTags($tags);

                // ---- SIGN USING SWENTEL EVENT ----
                $wire = $this->toWireEvent($index, $publicKey);
                $wire->setTags($tags);
                $signer->signEvent($wire, $bot->getNsec());
                $this->applySignedWireToEntity($wire, $index);
                // -----------------------------------

                $this->em->persist($index);
                $io->writeln(" + Updated index d='{$slug}': added {$added} article(s).");
                $totalUpdated++;
            } else {
                $io->writeln(" - Index d='{$slug}': no new matches.");
            }
        }

        if ($totalUpdated > 0) {
            $this->em->flush();
        }

        $io->success("Done. Updated {$totalUpdated} index(es).");
        return Command::SUCCESS;
    }

    private function firstTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $t) {
            if (($t[0] ?? null) === $name && isset($t[1])) {
                return (string)$t[1];
            }
        }
        return null;
    }

    private function allTagValues(array $tags, string $name): array
    {
        $out = [];
        foreach ($tags as $t) {
            if (($t[0] ?? null) === $name && isset($t[1])) {
                $out[] = (string)$t[1];
            }
        }
        return $out;
    }

    private function normTag(?string $t): string
    {
        $t = trim((string)$t);
        if ($t !== '' && $t[0] === '#') $t = substr($t, 1);
        return mb_strtolower($t);
    }

    private function intersects(array $a, array $b): bool
    {
        if (!$a || !$b) return false;
        $set = array_fill_keys($b, true);
        foreach ($a as $x) if (isset($set[$x])) return true;
        return false;
    }

    private function sortedATagsLast(array $tags): array
    {
        $aTags = [];
        $other = [];
        foreach ($tags as $t) {
            if (($t[0] ?? null) === 'a' && isset($t[1])) $aTags[] = $t;
            else $other[] = $t;
        }
        usort($aTags, fn($x, $y) => strcmp(strtolower($x[1]), strtolower($y[1])));
        return array_merge($other, $aTags);
    }

    /**
     * Build a swentel wire event from your DB entity so we can sign it.
     */
    private function toWireEvent(DbEvent $e, string $pubkey): WireEvent
    {
        $w = new WireEvent();
        $w->setKind($e->getKind());
        $createdAt = $e->getCreatedAt();
        // accept int or DateTimeInterface
        if ($createdAt instanceof \DateTimeInterface) {
            $w->setCreatedAt($createdAt->getTimestamp());
        } else {
            $w->setCreatedAt((int)$createdAt ?: time());
        }
        $w->setContent((string)($e->getContent() ?? ''));
        $w->setTags($e->getTags() ?? []);
        $w->setPublicKey($pubkey); // ensure pubkey is set for id computation
        return $w;
    }

    /**
     * Copy signature/id (and any normalized fields) back to your entity.
     */
    private function applySignedWireToEntity(WireEvent $w, DbEvent $e): void
    {
        if (method_exists($e, 'setId') && $w->getId()) {
            $e->setId($w->getId());
        }
        if (method_exists($e, 'setSig') && $w->getSignature()) {
            $e->setSig($w->getSignature());
        }
        if (method_exists($e, 'setPubkey') && $w->getPublicKey()) {
            $e->setPubkey($w->getPublicKey());
        }
        // keep tags/content in sync (in case swentel normalized)
        if (method_exists($e, 'setTags')) {
            $e->setTags($w->getTags());
        }
        if (method_exists($e, 'setContent')) {
            $e->setContent($w->getContent());
        }
        if (method_exists($e, 'setCreatedAt') && is_int($w->getCreatedAt())) {
            // optional: keep your entity’s createdAt as int or DateTime, depending on your schema
            try {
                $e->setCreatedAt($w->getCreatedAt());
            } catch (\TypeError $t) {
                // if your setter expects DateTimeImmutable:
                if ($w->getCreatedAt()) {
                    $e->setCreatedAt((new \DateTimeImmutable())->setTimestamp($w->getCreatedAt())->getTimestamp());
                }
            }
        }
        // also ensure kind stays set
        if (method_exists($e, 'setKind')) {
            $e->setKind($w->getKind());
        }
    }
}
