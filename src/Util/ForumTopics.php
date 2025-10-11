<?php

declare(strict_types=1);

namespace App\Util;

class ForumTopics
{
    public const TOPICS = [
        // ─────────── LIFESTYLE ───────────
        'lifestyle' => [
            'name' => 'Lifestyle',
            'subcategories' => [
                'travel' => [
                    'name' => 'Travel & Exploration',
                    'tags' => ['travel', 'adventure', 'destinations', 'culture', 'photography'],
                ],
                'health' => [
                    'name' => 'Health & Wellness',
                    'tags' => ['health', 'fitness', 'wellness', 'nutrition', 'meditation', 'mental-health'],
                ],
                'relationships' => [
                    'name' => 'Relationships & Family',
                    'tags' => ['relationships', 'family', 'parenting', 'marriage', 'friendship', 'community'],
                ],
                'christianity' => [
                    'name' => 'Christianity & Faith',
                    'tags' => ['jesus', 'christian', 'bible', 'faith', 'spirituality', 'religion'],
                ],
                'philosophy' => [
                    'name' => 'Philosophy & Ethics',
                    'tags' => ['philosophy', 'ethics', 'existentialism', 'metaphysics', 'logic'],
                ],
                'education' => [
                    'name' => 'Education & Learning',
                    'tags' => ['education', 'learning', 'school', 'university', 'teaching', 'homeschool'],
                ],
                'finance' => [
                    'name' => 'Personal Finance',
                    'tags' => ['budgeting', 'saving', 'investing', 'debt', 'retirement', 'economy'],
                ],
            ],
        ],

        // ─────────── TECH ───────────
        'tech' => [
            'name' => 'Tech',
            'subcategories' => [
                'bitcoin' => [
                    'name' => 'Bitcoin & Sound Money',
                    'tags' => ['bitcoin', 'lightning', 'decentralization', 'freedom', 'privacy', 'sovereignty'],
                ],
                'ai' => [
                    'name' => 'Artificial Intelligence',
                    'tags' => ['ai', 'machine-learning', 'llm', 'neural-networks', 'automation', 'robotics'],
                ],
                'nostr' => [
                    'name' => 'Nostr & Decentralized Social',
                    'tags' => ['nostr', 'fediverse', 'social', 'protocol', 'identity', 'nip'],
                ],
                'software' => [
                    'name' => 'Software Development',
                    'tags' => ['code', 'programming', 'development', 'open-source', 'python', 'php', 'javascript'],
                ],
                'hardware' => [
                    'name' => 'Hardware & Gadgets',
                    'tags' => ['hardware', 'devices', 'gadgets', 'controllers', 'iot', 'electronics'],
                ],
                'cybersecurity' => [
                    'name' => 'Cybersecurity & Privacy',
                    'tags' => ['security', 'privacy', 'encryption', 'hacking', 'infosec', 'vpn'],
                ],
                'science' => [
                    'name' => 'Science & Innovation',
                    'tags' => ['science', 'innovation', 'research', 'biology', 'physics', 'space', 'technology'],
                ],
            ],
        ],

        // ─────────── ART & CULTURE ───────────
        'art' => [
            'name' => 'Art & Culture',
            'subcategories' => [
                'photography' => [
                    'name' => 'Photography',
                    'tags' => ['photography', 'photojournalism', 'portrait', 'street', 'nature'],
                ],
                'music' => [
                    'name' => 'Music',
                    'tags' => ['music', 'audio', 'sound', 'composition', 'performance', 'production'],
                ],
                'writing' => [
                    'name' => 'Writing & Literature',
                    'tags' => ['writing', 'literature', 'books', 'poetry', 'fiction', 'non-fiction'],
                ],
                'film' => [
                    'name' => 'Film & Video',
                    'tags' => ['film', 'video', 'cinema', 'documentary', 'animation', 'production'],
                ],
                'design' => [
                    'name' => 'Design & Creativity',
                    'tags' => ['design', 'art', 'creativity', 'ui', 'ux', 'graphic-design'],
                ],
                'history' => [
                    'name' => 'History & Society',
                    'tags' => ['history', 'society', 'politics', 'culture', 'anthropology', 'archaeology'],
                ],
            ],
        ],

        // ─────────── BUSINESS ───────────
        'business' => [
            'name' => 'Business',
            'subcategories' => [
                'entrepreneurship' => [
                    'name' => 'Entrepreneurship',
                    'tags' => ['entrepreneurship', 'startup', 'business', 'innovation', 'leadership'],
                ],
                'marketing' => [
                    'name' => 'Marketing & Sales',
                    'tags' => ['marketing', 'sales', 'advertising', 'branding', 'customer', 'growth'],
                ],
                'economics' => [
                    'name' => 'Economics & Finance',
                    'tags' => ['economics', 'finance', 'markets', 'trading', 'policy', 'macro'],
                ],
                'management' => [
                    'name' => 'Management & Strategy',
                    'tags' => ['management', 'strategy', 'operations', 'productivity', 'leadership'],
                ],
                'real-estate' => [
                    'name' => 'Real Estate',
                    'tags' => ['real-estate', 'property', 'housing', 'investment', 'development'],
                ],
            ],
        ],

        // ─────────── SPORTS ───────────
        'sports' => [
            'name' => 'Sports',
            'subcategories' => [
                'fitness' => [
                    'name' => 'Fitness & Training',
                    'tags' => ['fitness', 'training', 'exercise', 'health', 'athletics', 'performance'],
                ],
                'outdoor' => [
                    'name' => 'Outdoor Activities',
                    'tags' => ['outdoor', 'hiking', 'camping', 'climbing', 'adventure', 'nature'],
                ],
                'team-sports' => [
                    'name' => 'Team Sports',
                    'tags' => ['football', 'basketball', 'baseball', 'soccer', 'hockey', 'team'],
                ],
                'combat' => [
                    'name' => 'Combat Sports',
                    'tags' => ['mma', 'boxing', 'wrestling', 'martial-arts', 'combat', 'fighting'],
                ],
                'esports' => [
                    'name' => 'Esports & Gaming',
                    'tags' => ['esports', 'gaming', 'video-games', 'competition', 'streaming'],
                ],
            ],
        ],

        // ─────────── NEWS & POLITICS ───────────
        'news' => [
            'name' => 'News & Politics',
            'subcategories' => [
                'politics' => [
                    'name' => 'Politics & Government',
                    'tags' => ['politics', 'government', 'policy', 'election', 'democracy', 'law'],
                ],
                'world-news' => [
                    'name' => 'World News',
                    'tags' => ['news', 'world', 'international', 'geopolitics', 'diplomacy'],
                ],
                'us-news' => [
                    'name' => 'US News',
                    'tags' => ['us', 'america', 'united-states', 'domestic', 'national'],
                ],
                'activism' => [
                    'name' => 'Activism & Social Issues',
                    'tags' => ['activism', 'social', 'justice', 'equality', 'rights', 'protest'],
                ],
                'media' => [
                    'name' => 'Media & Journalism',
                    'tags' => ['media', 'journalism', 'press', 'reporting', 'freedom', 'censorship'],
                ],
            ],
        ],
    ];
}
