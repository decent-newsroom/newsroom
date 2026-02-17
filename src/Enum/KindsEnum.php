<?php

namespace App\Enum;

enum KindsEnum: int
{
    case METADATA = 0; // metadata, NIP-01
    case TEXT_NOTE = 1; // text note, NIP-01, will not implement
    case IMAGE = 20; // NIP-68, images
    case FOLLOWS = 3;
    case REPOST = 6; // Only wraps kind 1, NIP-18, will not implement
    case GENERIC_REPOST = 16; // Generic repost, original kind signalled in a "k" tag, NIP-18
    case FILE_METADATA = 1063; // NIP-94
    case INTERESTS = 10015; // NIP-51
    case BOOKMARKS = 10003; // NIP-51, Standard bookmarks list
    case COMMENTS = 1111;
    case HTTP_AUTH = 27235; // NIP-98, HTTP Auth
    case BOOKMARK_SETS = 30003; // NIP-51, Categorized bookmark sets
    case CURATION_SET = 30004; // NIP-51, Curation sets (articles/notes)
    case CURATION_VIDEOS = 30005; // NIP-51, Video curation sets
    case CURATION_PICTURES = 30006; // NIP-51, Picture curation sets
    case LONGFORM = 30023; // NIP-23
    case LONGFORM_DRAFT = 30024; // NIP-23
    case PUBLICATION_INDEX = 30040; // NKBIP-01
    case PUBLICATION_CONTENT = 30041; // NKBIP-01
    case ZAP_RECEIPT = 9735; // NIP-57, Zaps
    case HIGHLIGHTS = 9802;
    case RELAY_LIST = 10002; // NIP-65, Relay list metadata
    case APP_DATA = 30078; // NIP-78, Arbitrary custom app data
    case TABULAR_DATA = 1450; // NIP-XX, Tabular Data (CSV)
}
