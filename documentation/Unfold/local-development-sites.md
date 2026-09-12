# Local Development Unfold Sites

In the development environment, admins can create an Unfold site from **Admin > Unfold Sites > New Site** with **Create Locally**.

This writes the selected magazine coordinate and subdomain mapping to the local `unfold_site` table only. It does not open a signer, create a kind `30078` AppData event, or publish anything to Nostr relays.

The action is available only when `APP_ENV=dev`; production does not render the control and rejects direct requests to the endpoint. Local sites use the bundle's default theme because no AppData configuration is created.
