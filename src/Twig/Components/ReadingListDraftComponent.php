<?php

namespace App\Twig\Components;

use App\Dto\CategoryDraft;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class ReadingListDraftComponent
{
    use DefaultActionTrait;

    public ?CategoryDraft $draft = null;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function mount(): void
    {
        $this->reloadFromSession();
    }

    #[LiveListener('readingListUpdated')]
    public function refresh(): void
    {
        $this->reloadFromSession();
    }

    #[LiveAction]
    public function remove(string $coordinate): void
    {
        $session = $this->requestStack->getSession();
        $draft = $session->get('read_wizard');
        if ($draft instanceof CategoryDraft) {
            $draft->articles = array_values(array_filter($draft->articles, fn($c) => $c !== $coordinate));
            $session->set('read_wizard', $draft);
            $this->draft = $draft;
        }
    }

    private function reloadFromSession(): void
    {
        $session = $this->requestStack->getSession();
        $data = $session->get('read_wizard');
        if ($data instanceof CategoryDraft) {
            $this->draft = $data;
            if (!$this->draft->slug) {
                $this->draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
                $session->set('read_wizard', $this->draft);
            }
            return;
        }

        $this->draft = new CategoryDraft();
        $this->draft->title = 'Reading List';
        $this->draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
        $session->set('read_wizard', $this->draft);
    }
}
