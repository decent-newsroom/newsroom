import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

// 01 - Base styles (theme, fonts, typography, reset)
import './styles/01-base/fonts.css';
import './styles/01-base/theme.css';
import './styles/01-base/spacing.css';
import './styles/01-base/reset.css';
import './styles/01-base/typography.css';

// 02 - Layout (grid, header, navigation, main content)
import './styles/02-layout/layout.css';
import './styles/02-layout/header.css';

// 03 - Components (reusable UI components)
import './styles/03-components/button.css';
import './styles/03-components/cards-shared.css';
import './styles/03-components/card.css';
import './styles/03-components/card-placeholder.css';
import './styles/03-components/dropdown.css';
import './styles/03-components/form.css';
import './styles/03-components/article.css';
import './styles/03-components/modal.css';
import './styles/03-components/notice.css';
import './styles/03-components/spinner.css';
import './styles/03-components/a2hs.css';
import './styles/03-components/og.css';
import './styles/03-components/nostr-previews.css';
import './styles/reading-lists.css';
import './styles/03-components/nip05-badge.css';
import './styles/03-components/picture-event.css';
import './styles/03-components/video-event.css';
import './styles/03-components/search.css';
import './styles/03-components/image-upload.css';
import './styles/03-components/zaps.css';
import './styles/03-components/back-to-top.css';
import './styles/03-components/article-nav.css';
import './styles/03-components/article-actions-dropdown.css';
import './styles/03-components/magazine-preview.css';
import './styles/03-components/featured-unfold.css';

// Toast notifications
import './styles/toast.css';

// Editor layout
import './styles/editor-layout.css';
import './styles/advanced-metadata.css';
import './styles/media-discovery.css';

// 04 - Page-specific styles
import './styles/04-pages/landing.css';
import './styles/04-pages/admin.css';
import './styles/04-pages/analytics.css';
import './styles/04-pages/author-media.css';
import './styles/04-pages/forum.css';
import './styles/04-pages/highlights.css';
import './styles/04-pages/discover.css';
import './styles/04-pages/subscription.css';
import './styles/04-pages/magazine-wizard.css';
import './styles/04-pages/my-content.css';
import './styles/04-pages/media-manager.css';
import './styles/04-pages/home-feed.css';
import './styles/04-pages/settings.css';
import './styles/04-pages/curation-page.css';
import './styles/04-pages/curation-picture-grid.css';
import './styles/04-pages/curation-video-playlist.css';
import './styles/04-pages/translation-helper.css';
import './styles/04-pages/blog-journey.css';

// 05 - Utilities (last for highest specificity)
import './styles/05-utilities/utilities.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

import Prism from 'prismjs';

import 'katex/dist/katex.min.css';
// KaTeX rendering is handled by the utility--katex Stimulus controller
// so it works reliably with Turbo navigations and PWA

// Run Prism on first load and after every Turbo navigation
function highlightCode() { Prism.highlightAll(); }
highlightCode();
document.addEventListener('turbo:load', highlightCode);
