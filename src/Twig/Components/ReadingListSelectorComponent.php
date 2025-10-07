<?php

namespace App\Twig\Components;

use App\Dto\CategoryDraft;
use App\Service\ReadingListManager;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class ReadingListSelectorComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $selectedSlug = '';

    public array $availableLists = [];
    public ?CategoryDraft $currentDraft = null;

    public function __construct(
        private readonly ReadingListManager $readingListManager,
    ) {}

    public function mount(): void
    {
        $this->availableLists = $this->readingListManager->getUserReadingLists();
        $selectedSlug = $this->readingListManager->getSelectedListSlug();
        $this->selectedSlug = $selectedSlug ?? '';
        $this->currentDraft = $this->readingListManager->getCurrentDraft();
    }

    #[LiveAction]
    public function selectList(string $slug): void
    {
        if ($slug === '__new__') {
            // Create new draft
            $this->currentDraft = $this->readingListManager->createNewDraft();
            $this->selectedSlug = '';
        } else {
            // Load existing list
            $this->currentDraft = $this->readingListManager->loadPublishedListIntoDraft($slug);
            $this->selectedSlug = $slug;
        }

        $this->dispatchBrowserEvent('readingListUpdated');
    }
}
