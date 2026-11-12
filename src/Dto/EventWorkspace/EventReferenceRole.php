<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

enum EventReferenceRole: string
{
    case Reference = 'reference';
    case Content = 'content';
    case Follow = 'follow';
    case Bookmark = 'bookmark';
    // A recommendation within a pack, not a claim that the viewer follows it.
    case FollowPackMember = 'follow_pack_member';
    case Root = 'root';
    case Parent = 'parent';
}
