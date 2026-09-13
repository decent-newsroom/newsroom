<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

enum PublicationMount: string
{
    case SUBDOMAIN = 'subdomain';
    case COORDINATE = 'coordinate';
}
