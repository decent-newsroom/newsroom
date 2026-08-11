<?php
namespace App\Controller;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FeedbackController extends AbstractController
{
    #[Route('/feedback', name: 'feedback_form', methods: ['GET'])]
    public function form(): Response
    {
        $recipients = [
            (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ('npub1ez09adke4vy8udk3y2skwst8q5chjgqzym9lpq4u58zf96zcl7kqyry2lz')),
            (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ('npub1636uujeewag8zv8593lcvdrwlymgqre6uax4anuq3y5qehqey05sl8qpl4')),
        ];
        return $this->render('feedback/form.html.twig', [
            'recipients' => $recipients,
        ]);
    }
}
