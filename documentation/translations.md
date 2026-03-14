# Translations (i18n)
Decent Newsroom uses Symfony's built-in translation system to support multiple languages.
## How it works
1. Translation files live in `/translations/` as YAML files named `messages.{locale}.yaml`.
2. English (en) is the default and fallback language.
3. Templates use the `|trans` Twig filter.
4. The locale is stored in the user session via `LocaleSubscriber`.
5. A language switcher in the footer lets users switch languages.
## Architecture
- `config/packages/translation.yaml` - sets default_locale, enabled_locales, and fallbacks.
- `src/Controller/LocaleController.php` - handles GET /locale/{locale}, stores locale in session.
- `src/EventSubscriber/LocaleSubscriber.php` - reads _locale from session on each request.
- `templates/components/Footer.html.twig` - renders the language switcher.
## Adding a new language
1. Copy `translations/messages.en.yaml` to `translations/messages.{locale}.yaml`.
2. Translate all values (keep the keys unchanged).
3. Add the locale to `enabled_locales` in `config/packages/translation.yaml`.
4. Add a `language.{locale}` entry in all translation files for the language name.
