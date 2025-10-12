<?php
namespace App\Controller;

use App\Util\NostrKeyUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FeedbackController extends AbstractController
{
    #[Route('/feedback', name: 'feedback_form', methods: ['GET'])]
    public function form(NostrKeyUtil $keyUtil): Response
    {
        $recipients = [
            $keyUtil->npubToHex('npub1ez09adke4vy8udk3y2skwst8q5chjgqzym9lpq4u58zf96zcl7kqyry2lz'),
            $keyUtil->npubToHex('npub1636uujeewag8zv8593lcvdrwlymgqre6uax4anuq3y5qehqey05sl8qpl4'),
        ];
        return $this->render('feedback/form.html.twig', [
            'recipients' => $recipients,
        ]);
    }
}
