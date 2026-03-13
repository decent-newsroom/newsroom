<?php

declare(strict_types=1);

namespace App\Service\Media;

use Psr\Log\LoggerInterface;

/**
 * Registry of configured media upload providers.
 *
 * Manages Blossom and NIP-96 provider instances based on configuration.
 * Providers are not auto-discovered in v1; they are explicitly configured.
 *
 * @see §5.1 of multimedia-manager spec
 */
class MediaProviderRegistry
{
    /** @var MediaProviderInterface[] */
    private array $providers = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->registerDefaults();
    }

    /**
     * Get all registered providers.
     *
     * @return MediaProviderInterface[]
     */
    public function getAll(): array
    {
        return $this->providers;
    }

    /**
     * Get a provider by its ID.
     */
    public function get(string $id): ?MediaProviderInterface
    {
        return $this->providers[$id] ?? null;
    }

    /**
     * Register a provider.
     */
    public function register(MediaProviderInterface $provider): void
    {
        $this->providers[$provider->getId()] = $provider;
        $this->logger->debug('Registered media provider', [
            'id' => $provider->getId(),
            'protocol' => $provider->getProtocol(),
        ]);
    }

    /**
     * Get providers that support upload.
     *
     * @return MediaProviderInterface[]
     */
    public function getUploadProviders(): array
    {
        return array_filter($this->providers, fn(MediaProviderInterface $p) => $p->supportsUpload());
    }

    /**
     * Get providers that support listing by pubkey.
     *
     * @return MediaProviderInterface[]
     */
    public function getListByPubkeyProviders(): array
    {
        return array_filter($this->providers, fn(MediaProviderInterface $p) => $p->supportsListByPubkey());
    }

    /**
     * Serialize all providers for API response.
     */
    public function toArray(): array
    {
        return array_map(fn(MediaProviderInterface $p) => $p->toArray(), array_values($this->providers));
    }

    /**
     * Register the default providers matching existing integrations.
     */
    private function registerDefaults(): void
    {
        // NIP-96 providers (matching existing ImageUploadController endpoints)
        $this->register(new Nip96MediaProvider(
            id: 'nostrbuild',
            label: 'nostr.build',
            baseUrl: 'https://nostr.build',
            apiUrl: 'https://nostr.build/api/v2/nip96/upload',
            logger: $this->logger,
        ));

        $this->register(new Nip96MediaProvider(
            id: 'nostrcheck',
            label: 'nostrcheck.me',
            baseUrl: 'https://nostrcheck.me',
            apiUrl: 'https://nostrcheck.me/api/v2/media',
            logger: $this->logger,
        ));

        $this->register(new Nip96MediaProvider(
            id: 'sovbit',
            label: 'Sovbit Files',
            baseUrl: 'https://files.sovbit.host',
            apiUrl: 'https://files.sovbit.host/api/v2/media',
            logger: $this->logger,
        ));

        // Blossom providers
        $this->register(new BlossomMediaProvider(
            id: 'blossom-primal',
            label: 'blossom.primal.net',
            baseUrl: 'https://blossom.primal.net',
            listEnabled: true,
            logger: $this->logger,
        ));

        $this->register(new BlossomMediaProvider(
            id: 'blossomband',
            label: 'blossom.band',
            baseUrl: 'https://blossom.band',
            listEnabled: true,
            logger: $this->logger,
        ));
    }
}

