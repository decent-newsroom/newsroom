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
import './styles/03-components/form.css';
import './styles/03-components/article.css';
import './styles/03-components/modal.css';
import './styles/03-components/notice.css';
import './styles/03-components/spinner.css';
import './styles/03-components/a2hs.css';
import './styles/03-components/og.css';
import './styles/03-components/nostr-previews.css';
import './styles/03-components/picture-event.css';
import './styles/03-components/search.css';
import './styles/03-components/image-upload.css';

// 04 - Page-specific styles
import './styles/04-pages/landing.css';
import './styles/04-pages/admin.css';
import './styles/04-pages/analytics.css';
import './styles/04-pages/author-media.css';

// 05 - Utilities (last for highest specificity)
import './styles/05-utilities/utilities.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
