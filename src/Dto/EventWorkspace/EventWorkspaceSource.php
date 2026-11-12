<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

enum EventWorkspaceSource: string
{
    case OwnedCollectionEvent = 'owned_collection_event';
    case AuthoredArticle = 'authored_article';
    case LocalSubscription = 'local_subscription';
    case BookshelfDirectory = 'bookshelf_directory';
}
