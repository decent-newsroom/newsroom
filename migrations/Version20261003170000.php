<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Durable outbox for signed reader interactions (Unfold comments/likes/reposts).
 *
 * Local acceptance (verification + projection) and this outbox row are
 * committed atomically in a single SQL transaction (see
 * App\Unfold\SignedInteractionPublisher). The row survives relay/queue
 * failures, enabling bounded automatic retries and crash-safe recovery
 * via a lease-based claim (no long network-held DB locks).
 *
 * Additive only: this migration must not be auto-applied against a live
 * database without explicit operator approval.
 */
final class Version20261003170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reader_interaction_outbox durable delivery table for Unfold reader interactions (comments/likes/reposts)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reader_interaction_outbox (
                id SERIAL PRIMARY KEY,
                event_id VARCHAR(64) NOT NULL,
                reader_pubkey VARCHAR(64) NOT NULL,
                publication_coordinate VARCHAR(800) NOT NULL,
                target_coordinate VARCHAR(800) NOT NULL,
                kind INT NOT NULL,
                action VARCHAR(16) NOT NULL,
                signed_event JSONB NOT NULL,
                relays JSONB NOT NULL DEFAULT '[]',
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                relay_results JSONB NOT NULL DEFAULT '{}',
                attempts INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 3,
                next_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                leased_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_reader_interaction_outbox_event_id ON reader_interaction_outbox (event_id)');
        $this->addSql('CREATE INDEX idx_reader_interaction_outbox_owner_target ON reader_interaction_outbox (reader_pubkey, target_coordinate, publication_coordinate)');
        $this->addSql('CREATE INDEX idx_reader_interaction_outbox_due ON reader_interaction_outbox (status, next_attempt_at)');

        $this->addSql("COMMENT ON TABLE reader_interaction_outbox IS 'Durable delivery outbox for signed reader comment/like/repost events (Unfold Spec 07)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS reader_interaction_outbox');
    }
}
