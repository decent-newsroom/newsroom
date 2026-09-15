# Publishing services and access models

The running application has several independent subscription and membership features. Their concrete behavior is documented with each feature.

| Feature | Current purpose |
|---|---|
| [Vanity names](vanity-names.md) | Paid NIP-05 identity and profile aliases. |
| [Active indexing](../Newsroom/active-indexing-service.md) | On-demand author indexing subscriptions. |
| [Publication subdomains](publication-subdomain.md) | Paid hosting and local Unfold site setup. |
| [Essayist](../Essayist/essayist.md) | Member contributions, reading access, and membership relay authentication. |
| [Visitor analytics](visitor-analytics.md) | Operator traffic reporting. |
| [Tips](payment-targets.md) and [zaps](../LN/zaps.md) | Creator payment targets and Lightning payments. |

[Application architecture](../Guides/architecture.md) describes the technical system. [Pricing and footer](footer-and-pricing.md) documents the available subscription entry points.

## Future publication subscriptions

Earlier ReWire diagrams combined relay publisher grants, creator scope subscriptions, and client premium tiers into one proposed business model. Those diagrams did not describe a fully implemented checkout or entitlement system.

Publication audience offers and scoped access remain a separate design/integration effort. Consult the [Unfold documentation](../Unfold/unfold.md) and package specifications for current boundaries. Do not infer working Stripe billing, tiered Creator/Enterprise plans, or content entitlements from the old architecture diagrams.
