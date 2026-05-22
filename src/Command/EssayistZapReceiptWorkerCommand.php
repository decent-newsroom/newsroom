<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Repository\EventRepository;
use App\Repository\UserEntityRepository;
use App\Service\Essayist\EssayistMembershipService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Scans recent kind:9735 zap receipts and grants `ROLE_ESSAYIST_MEMBER` to
 * payers who zapped an existing Essayist member with at least the configured
 * minimum.
 *
 * Each receipt event id is the idempotency key — see
 * `EssayistMembershipService::recordGrant()`. Safe to run repeatedly.
 */
#[AsCommand(
    name: 'essayist:check-zap-receipts',
    description: 'Scan zap receipts and extend Essayist memberships for matching contributions'
)]
final class EssayistZapReceiptWorkerCommand extends Command
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly UserEntityRepository $userRepository,
        private readonly EssayistMembershipService $membershipService,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'essayist.membership.receipt_scan_minutes')]
        private readonly int $defaultScanMinutes = 60,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since-minutes', null, InputOption::VALUE_OPTIONAL, 'Scan window in minutes', null)
            ->setHelp('Run frequently via cron (every 5 minutes recommended).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $minutes  = (int) ($input->getOption('since-minutes') ?? $this->defaultScanMinutes);
        $since    = (new \DateTimeImmutable("-{$minutes} minutes"))->getTimestamp();

        // Resolve current member pubkeys (hex) — receipts to anyone NOT a member are ignored.
        $members      = $this->userRepository->findByRoleWithQuery(RolesEnum::ESSAYIST_MEMBER->value, null, 5000);
        $memberHexSet = [];
        foreach ($members as $member) {
            $npub = $member->getNpub();
            if ($npub && NostrKeyUtil::isNpub($npub)) {
                try {
                    $memberHexSet[NostrKeyUtil::npubToHex($npub)] = true;
                } catch (\Throwable) {
                }
            }
        }

        if (empty($memberHexSet)) {
            $io->info('No active Essayist members — nothing to match.');
            return Command::SUCCESS;
        }

        // Fetch recent zap receipts. Scoped by kind + recency only;
        // recipient/amount filtering happens in PHP below.
        $receipts = $this->eventRepository->createQueryBuilder('e')
            ->where('e.kind = :kind')
            ->andWhere('e.created_at >= :since')
            ->setParameter('kind', KindsEnum::ZAP_RECEIPT->value)
            ->setParameter('since', $since)
            ->orderBy('e.created_at', 'ASC')
            ->setMaxResults(1000)
            ->getQuery()
            ->getResult();

        $io->info(sprintf('Scanning %d zap receipt(s) from the last %d minute(s)…', count($receipts), $minutes));

        $granted = 0;
        $skipped = 0;

        /** @var Event $receipt */
        foreach ($receipts as $receipt) {
            $info = $this->parseReceipt($receipt);
            if ($info === null) {
                $skipped++;
                continue;
            }

            // Sponsor (zap recipient) must currently be an Essayist member.
            if (!isset($memberHexSet[$info['sponsor']])) {
                continue;
            }

            // Don't let a member zap themselves to extend their own membership.
            if ($info['payer'] === $info['sponsor']) {
                continue;
            }

            try {
                $grant = $this->membershipService->recordGrant(
                    $info['payer'],
                    $info['sponsor'],
                    $receipt->getEventId() ?? '',
                    $info['amount_sats'],
                    (new \DateTimeImmutable('@' . $receipt->getCreatedAt())),
                );
            } catch (\Throwable $e) {
                $this->logger->error('Failed to record essayist grant', [
                    'receipt' => $receipt->getEventId(),
                    'error'   => $e->getMessage(),
                ]);
                continue;
            }

            if ($grant !== null) {
                $granted++;
                $io->success(sprintf(
                    'Granted/extended membership for %s (sponsor %s, %d sats, until %s)',
                    substr($info['payer'], 0, 12) . '…',
                    substr($info['sponsor'], 0, 12) . '…',
                    $info['amount_sats'],
                    $grant->getExpiresAt()->format('Y-m-d H:i'),
                ));
            }
        }

        $io->info(sprintf('Done: %d grants recorded, %d receipts skipped.', $granted, $skipped));

        return Command::SUCCESS;
    }

    /**
     * Extract { payer, sponsor, amount_sats } from a kind:9735 receipt.
     *
     * Per NIP-57:
     *  - `p` tag on the receipt = recipient (sponsor)
     *  - `description` tag = JSON-encoded kind:9734 zap request signed by the payer
     *  - `bolt11` tag on receipt or `amount` tag on the request = amount in millisats
     *
     * @return array{payer:string,sponsor:string,amount_sats:int}|null
     */
    private function parseReceipt(Event $receipt): ?array
    {
        $sponsor    = null;
        $amountMsat = null;
        $request    = null;

        foreach ($receipt->getTags() as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }
            switch ($tag[0]) {
                case 'p':
                    if (NostrKeyUtil::isHexPubkey((string) $tag[1])) {
                        $sponsor = (string) $tag[1];
                    }
                    break;
                case 'amount':
                    $amountMsat = (int) $tag[1];
                    break;
                case 'description':
                    $decoded = json_decode((string) $tag[1], true);
                    if (is_array($decoded)) {
                        $request = $decoded;
                    }
                    break;
            }
        }

        if ($sponsor === null) {
            return null;
        }

        // Payer pubkey comes from the embedded zap request (NIP-57).
        $payer = null;
        if (is_array($request) && isset($request['pubkey']) && NostrKeyUtil::isHexPubkey((string) $request['pubkey'])) {
            $payer = (string) $request['pubkey'];
        }

        if ($payer === null) {
            return null;
        }

        // Fallback amount from the request's amount tag.
        if ($amountMsat === null && is_array($request['tags'] ?? null)) {
            foreach ($request['tags'] as $rt) {
                if (is_array($rt) && ($rt[0] ?? '') === 'amount' && isset($rt[1])) {
                    $amountMsat = (int) $rt[1];
                    break;
                }
            }
        }

        if (!$amountMsat || $amountMsat <= 0) {
            return null;
        }

        return [
            'payer'       => $payer,
            'sponsor'     => $sponsor,
            'amount_sats' => intdiv($amountMsat, 1000),
        ];
    }
}

