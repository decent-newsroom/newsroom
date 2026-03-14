# Contribution Widget

## Overview

The contribution widget displays a call-to-action in the left navigation sidebar, inviting users to support the project via Bitcoin Lightning Network zaps.

## Features

- **Prominent Display**: Shows in the left navigation menu below the UserMenu component
- **ZapButton Integration**: Uses the existing `ZapButton` LiveComponent for seamless NIP-57 Lightning payments
- **Configurable**: Easy to enable/disable and customize via environment variables
- **Beautiful UI**: Clean, centered design with emoji and helpful text

## Configuration

### Environment Variables

Add these to your `.env` file (or `.env.prod.local` for production):

```env
# Project contribution settings
PROJECT_CONTRIBUTION_NPUB=npub1yourkeyhere...
PROJECT_CONTRIBUTION_LUD16=youraddress@getalby.com
```

### Hide Contribution Section

To hide the contribution widget, simply leave the `PROJECT_CONTRIBUTION_NPUB` variable empty:

```env
PROJECT_CONTRIBUTION_NPUB=
```

## How It Works

1. **Global Configuration**: The npub and lightning address are configured in `config/services.yaml` as parameters
2. **Twig Globals**: These parameters are exposed as Twig global variables in `config/packages/twig.yaml`
3. **Template Display**: The `layout.html.twig` template checks if both values are set and displays the widget
4. **ZapButton**: When clicked, opens the existing ZapButton modal for creating Lightning invoices

## Technical Details

### Files Modified

- `config/services.yaml` - Added contribution parameters
- `config/packages/twig.yaml` - Exposed parameters as Twig globals
- `templates/layout.html.twig` - Added contribution widget HTML
- `.env.dist` - Documented environment variables

### Template Code

```twig
{% if contribution_npub and contribution_lud16 %}
    <div class="contribution-section mt-3 mb-3 p-3 border rounded bg-light">
        <div class="text-center mb-2">
            <strong>💜 Support this Project</strong>
        </div>
        <p class="small text-muted mb-2 text-center">
            Help us build the future of decentralized journalism
        </p>
        <div class="text-center">
            <twig:Molecules:ZapButton
                btnClass="btn-sm w-100"
                recipientPubkey="{{ contribution_npub|toHex }}"
                recipientLud16="{{ contribution_lud16 }}"
            />
        </div>
    </div>
{% endif %}
```

## Customization

You can customize the appearance and messaging by editing the contribution section in `templates/layout.html.twig`:

- **Title**: Change the "💜 Support this Project" text
- **Description**: Update the help text below the title
- **Button Style**: Modify the `btnClass` parameter (e.g., `btn-sm w-100`)
- **Styling**: Adjust Bootstrap classes on the container div

## Best Practices

1. **Set Your Own Values**: In production, replace the default lightning address with your own
2. **Test First**: Verify zaps work correctly before deploying to production
3. **Monitor**: Check that payments are received at your configured lightning address
4. **Update Regularly**: Keep your lightning address active and monitored

## Related Components

- **ZapButton** (`src/Twig/Components/Molecules/ZapButton.php`) - The Lightning payment component
- **NIP-57** - Nostr protocol for Lightning zaps
- **LNURL** - Lightning URL protocol for invoice generation
