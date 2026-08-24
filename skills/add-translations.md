# Skill: Add Translations (i18n)

Use this skill whenever you add user-facing text. All strings must be in the translation files, never hardcoded in templates or PHP.

---

## Translation files

```
translations/
    messages.en.yaml   ← English (source)
    messages.de.yaml   ← German
    messages.es.yaml   ← Spanish
    messages.fr.yaml   ← French
    messages.sl.yaml   ← Slovenian
```

Always add to **all five files**. If you don't know the translation, use the English string as a placeholder and mark it with a comment `# TODO: translate`.

---

## Key naming conventions

Keys are dot-separated, organised by feature area:

```yaml
# Pattern: {feature}.{sub_feature}.{element}
home_feed.tab.activity: "Activity"
article.actions.bookmark: "Bookmark"
settings.relays.title: "My Relays"
admin.bans.add_label: "Ban pubkey"
```

Keep keys lowercase with underscores. Reflect the template hierarchy.

---

## 1. Add to English source first

File: `translations/messages.en.yaml`

```yaml
your_feature:
    title: "Your Feature"
    description: "This feature does something useful."
    action_save: "Save"
    action_cancel: "Cancel"
    empty_state: "No items yet."
    count: "{count, plural, one{# item} other{# items}}"
```

---

## 2. Add to all other locales

Repeat the same keys in `messages.de.yaml`, `messages.es.yaml`, `messages.fr.yaml`, `messages.sl.yaml`.

For placeholder translations:

```yaml
# messages.de.yaml
your_feature:
    title: "Your Feature"      # TODO: translate
    description: "This feature does something useful."  # TODO: translate
```

---

## 3. Use in Twig templates

```twig
{# Simple key #}
{{ 'your_feature.title'|trans }}

{# With parameters #}
{{ 'your_feature.count'|trans({'%count%': items|length}) }}

{# In an attribute #}
<button title="{{ 'your_feature.action_save'|trans }}">
    {{ 'your_feature.action_save'|trans }}
</button>
```

---

## 4. Use in PHP (controllers, services)

```php
use Symfony\Contracts\Translation\TranslatorInterface;

public function __construct(
    private readonly TranslatorInterface $translator,
) {}

$message = $this->translator->trans('your_feature.title');
```

---

## Pluralisation

Use ICU message format for plural forms:

```yaml
# messages.en.yaml
relay.count: "{count, plural, one{# relay} other{# relays}}"
```

```twig
{{ 'relay.count'|trans({'%count%': relays|length}) }}
```

---

## Checklist

- [ ] Keys added to `messages.en.yaml`
- [ ] Same keys added to `messages.de.yaml`, `messages.es.yaml`, `messages.fr.yaml`, `messages.sl.yaml`
- [ ] Templates use `|trans` — no hardcoded strings
- [ ] Keys follow the `{feature}.{element}` dot-notation pattern
- [ ] Plurals use ICU format

