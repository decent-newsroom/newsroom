<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\IndexStatusEnum;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Service\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Redis as RedisClient;

#[AsCommand(name: 'app:yaml_to_nostr', description: 'Traverses folders, converts YAML files to JSON using object mapping, and saves the result in Redis cache.')]
class NostrEventFromYamlDefinitionCommand extends Command
{
    private string $nsec;

    public function __construct(private readonly CacheInterface           $redisCache,
                                private readonly NostrClient              $client,
                                private readonly ArticleFactory           $factory,
                                ParameterBagInterface                     $bag,
                                private readonly ObjectPersisterInterface $itemPersister,
                                private readonly EntityManagerInterface   $entityManager,
                                private readonly RedisClient              $redis)
    {
        $this->nsec = $bag->get('nsec');
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('folder', InputArgument::REQUIRED, 'The folder location to start scanning from.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $folder = $input->getArgument('folder');

        // Use Symfony Finder to locate YAML files recursively
        $finder = new Finder();
        $finder->files()
            ->in($folder)
            ->name('*.yaml')
            ->name('*.yml');

        if (!$finder->hasResults()) {
            $output->writeln('<comment>No YAML files found in the specified directory.</comment>');
            return Command::SUCCESS;
        }

        $articleSlugsList = [];

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            $output->writeln("<info>Processing file: $filePath</info>");
            $yamlContent = Yaml::parseFile($filePath);  // This parses the YAML file

            try {
                // Deserialize YAML content into an Event object
                $event = new Event();
                $event->setKind(KindsEnum::PUBLICATION_INDEX->value);
                $tags = $yamlContent['tags'];
                $event->setTags($tags);
                $items = array_filter($tags, function ($tag) {
                    return ($tag[0] === 'a');
                });
                foreach ($items as $one) {
                    $parts = explode(':', $one[1]);
                    $articleSlugsList[] = end($parts);
                }

                $signer = new Sign();
                $signer->signEvent($event, $this->nsec);

                // Save to cache
                $slug = array_filter($tags, function ($tag) {
                    return ($tag[0] === 'd');
                });
                $slug = $slug[0][1] ?? null;
                if ($slug) {
                    $cacheKey = 'magazine-' . $slug;
                    $cacheItem = $this->redisCache->getItem($cacheKey);
                    $cacheItem->set($event);
                    $this->redisCache->save($cacheItem);

                    // If top-level magazine (has 'a' tags referencing 30040 categories), record slug
                    $isTopLevelMagazine = false;
                    foreach ($tags as $t) {
                        if (($t[0] ?? null) === 'a' && isset($t[1]) && str_starts_with((string)$t[1], '30040:')) {
                            $isTopLevelMagazine = true; break;
                        }
                    }
                    if ($isTopLevelMagazine) {
                        try { $this->redis->sAdd('magazine_slugs', $slug); } catch (\Throwable) {}
                    }
                }

                $output->writeln("<info>Saved index.</info>");
            } catch (\Exception $e) {
                $output->writeln("<error>Error deserializing YAML in file: $filePath. Message: {$e->getMessage()}</error>");
                continue;
            }
        }

        // crawl relays for all the articles and save to db
        $fresh = $this->client->getArticles($articleSlugsList);
        $articles = [];
        foreach ($fresh as $item) {
            $article = $this->factory->createFromLongFormContentEvent($item);
            $article->setIndexStatus(IndexStatusEnum::TO_BE_INDEXED);
            $this->entityManager->persist($article);
            $articles[] = $article;
        }
        $this->entityManager->flush();

        // to elastic
        if (count($articles) > 0 ) {
            $this->itemPersister->insertMany($articles); // Insert or skip existing
            // Set all articles as indexed
            foreach ($articles as $article) {
                $article->setIndexStatus(IndexStatusEnum::INDEXED);
                $this->entityManager->persist($article);
            }
            $this->entityManager->flush();
            $output->writeln('<info>Added to index.</info>');
        }

        $output->writeln('<info>Conversion complete.</info>');
        return Command::SUCCESS;
    }
}
