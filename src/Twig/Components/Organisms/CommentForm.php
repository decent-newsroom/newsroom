<?php
namespace App\Twig\Components\Organisms;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class CommentForm
{
    public string $publish_url;
    public string $csrf_token;
    public array $root_context = [];
    public array $parent_context = [];
    public string $form_id;

    public function mount(
        string $publish_url,
        string $csrf_token,
        array $root_context,
        array $parent_context,
        ?string $form_id = null
    ): void {
        $this->publish_url = $publish_url;
        $this->csrf_token = $csrf_token;
        $this->root_context = $root_context;
        $this->parent_context = $parent_context;
        $this->form_id = $form_id ?? uniqid('nip22_comment_');
    }
}


