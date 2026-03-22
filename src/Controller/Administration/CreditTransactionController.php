<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Credits\Entity\CreditTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @deprecated Since v0.0.21 — Search credits system has been deprecated. Transaction records are no longer generated.
 */
class CreditTransactionController extends AbstractController
{
    #[Route('/admin/transactions', name: 'admin_credit_transactions')]
    public function index(EntityManagerInterface $em): Response
    {
        $transactions = $em->getRepository(CreditTransaction::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->render('admin/transactions.html.twig', [
            'transactions' => $transactions,
        ]);
    }
}
