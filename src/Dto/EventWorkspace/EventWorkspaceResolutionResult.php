<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

/**
 * Resolution outcome for one identity; it never equates lookup failure with
 * missing data and only exposes a presentation in the available state.
 */
final readonly class EventWorkspaceResolutionResult
{
    private function __construct(
        public EventWorkspaceIdentity $identity,
        public EventWorkspaceResolutionStatus $status,
        public ?EventPresentation $presentation,
    ) {
        if (($status === EventWorkspaceResolutionStatus::Available) !== ($presentation !== null)) {
            throw new \InvalidArgumentException('Only available results may include a presentation.');
        }

        if ($presentation !== null && !self::matches($identity, $presentation)) {
            throw new \InvalidArgumentException('The presentation does not match the requested workspace identity.');
        }
    }

    public static function available(EventWorkspaceIdentity $identity, EventPresentation $presentation): self
    {
        return new self($identity, EventWorkspaceResolutionStatus::Available, $presentation);
    }

    public static function missing(EventWorkspaceIdentity $identity): self
    {
        return new self($identity, EventWorkspaceResolutionStatus::Missing, null);
    }

    public static function loading(EventWorkspaceIdentity $identity): self
    {
        return new self($identity, EventWorkspaceResolutionStatus::Loading, null);
    }

    public static function unavailable(EventWorkspaceIdentity $identity): self
    {
        return new self($identity, EventWorkspaceResolutionStatus::Unavailable, null);
    }

    public static function restricted(EventWorkspaceIdentity $identity): self
    {
        return new self($identity, EventWorkspaceResolutionStatus::Restricted, null);
    }

    public static function invalid(EventWorkspaceIdentity $identity): self
    {
        return new self($identity, EventWorkspaceResolutionStatus::Invalid, null);
    }

    public static function failed(EventWorkspaceIdentity $identity): self
    {
        return new self($identity, EventWorkspaceResolutionStatus::Failed, null);
    }

    private static function matches(EventWorkspaceIdentity $identity, EventPresentation $presentation): bool
    {
        return match ($identity->type) {
            EventWorkspaceIdentityType::EventId => strtolower($presentation->eventId) === $identity->value,
            EventWorkspaceIdentityType::Address => $presentation->coordinate === $identity->value
                && ($identity->versionEventId === null
                    || strtolower($presentation->eventId) === $identity->versionEventId),
            EventWorkspaceIdentityType::Profile => $presentation->kind === 0
                && strtolower($presentation->authorPubkey) === $identity->value,
        };
    }
}
