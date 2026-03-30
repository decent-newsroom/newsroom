<?php

namespace App\Enum;

enum KindsEnum: int
{
    case METADATA = 0; // metadata, NIP-01
    case TEXT_NOTE = 1; // text note, NIP-01, will not implement
    case FOLLOWS = 3;
    case DELETION_REQUEST = 5; // NIP-09, event deletion request
    case REPOST = 6; // Only wraps kind 1, NIP-18, will not implement
    case REACTION = 7; // NIP-25, reactions (+, -, emoji)
    case GENERIC_REPOST = 16; // Generic repost, original kind signalled in a "k" tag, NIP-18
    case IMAGE = 20; // NIP-68, images
    case VIDEO = 21; // NIP-71, video events
    case SHORT_VIDEO = 22; // NIP-71, short-form portrait video events
    case CHANNEL_CREATE = 40; // NIP-28, channel creation
    case CHANNEL_METADATA = 41; // NIP-28, channel metadata update
    case CHANNEL_MESSAGE = 42; // NIP-28, channel message (used for chat text messages)
    case CHANNEL_HIDE_MESSAGE = 43; // NIP-28, hide message
    case CHANNEL_MUTE_USER = 44; // NIP-28, mute user
    case FILE_METADATA = 1063; // NIP-94
    case COMMENTS = 1111;
    case TABULAR_DATA = 1450; // NIP-XX, Tabular Data (CSV)
    case REPORT = 1984; // NIP-56, content reporting
    case LABEL = 1985; // NIP-32, labeling / content classification
    case ZAP_REQUEST = 9734; // NIP-57, Zap request
    case ZAP_RECEIPT = 9735; // NIP-57, Zap receipt
    case HIGHLIGHTS = 9802; // NIP-84
    case MUTE_LIST = 10000; // NIP-51, user mute list
    case PIN_LIST = 10001; // NIP-51, pinned notes
    case RELAY_LIST = 10002; // NIP-65, Relay list metadata
    case BOOKMARKS = 10003; // NIP-51, Standard bookmarks list
    case INTERESTS = 10015; // NIP-51
    case MEDIA_FOLLOWS = 10020; // NIP-68, multimedia follow list
    case BLOSSOM_SERVER_LIST = 10063; // NIP-B7, user Blossom server list
    case HTTP_AUTH = 27235; // NIP-98, HTTP Auth
    case BOOKMARK_SETS = 30003; // NIP-51, Categorized bookmark sets
    case CURATION_SET = 30004; // NIP-51, Curation sets (articles/notes)
    case CURATION_VIDEOS = 30005; // NIP-51, Video curation sets
    case CURATION_PICTURES = 30006; // NIP-51, Picture curation sets
    case INTEREST_SETS = 30015; // NIP-51, Interest sets (hashtag groups)
    case LONGFORM = 30023; // NIP-23
    case LONGFORM_DRAFT = 30024; // NIP-23
    case PUBLICATION_INDEX = 30040; // NKBIP-01
    case PUBLICATION_CONTENT = 30041; // NKBIP-01
    case APP_DATA = 30078; // NIP-78, Arbitrary custom app data
    case PLAYLIST = 34139; // Playlist (e.g. Nostria music playlists)
    case ADDRESSABLE_VIDEO = 34235; // NIP-71, addressable video events
    case ADDRESSABLE_SHORT_VIDEO = 34236; // NIP-71, addressable short-form video events
    case FOLLOW_PACK = 39089; // NIP-51, Follow pack / starter pack (list of npubs)
}
