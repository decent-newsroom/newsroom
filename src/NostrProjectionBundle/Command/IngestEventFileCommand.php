<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Command;

use DecentNewsroom\NostrProjectionBundle\Application\Ingest\IngestRawEvent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'nostr:projection:ingest-event-file',
    description: 'Ingest a raw Nostr event from a JSON file through the projection spine.',
)]
final class IngestEventFileCommand extends Command
{
    public function __construct(
        private readonly IngestRawEvent $ingestRawEvent,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to a JSON file containing one raw Nostr event.')
            ->addArgument('source-relay', InputArgument::OPTIONAL, 'Optional source relay URL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');

        if (!is_file($file)) {
            $output->writeln(sprintf('<error>File not found: %s</error>', $file));
            return Command::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            $output->writeln('<error>JSON file must contain an object.</error>');
            return Command::FAILURE;
        }

        /** @var array<string, mixed> $payload */
        $result = ($this->ingestRawEvent)(
            rawEvent: $payload,
            sourceRelay: $input->getArgument('source-relay') !== null ? (string) $input->getArgument('source-relay') : null,
        );

        $output->writeln(sprintf(
            '<info>Ingested event %s. inserted=%s current_record_changed=%s</info>',
            $result->eventId->toString(),
            $result->inserted ? 'yes' : 'no',
            $result->currentRecordChanged ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    }
}
