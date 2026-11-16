# WooCommerce Fraud Protection documentation

Start here. The documents are grouped by what you want to do.

## I want to understand how it all works

The tutorial builds up from WooCommerce's checkout to the plugin's internals. Read it in order the first time; each chapter assumes the previous ones.

1. [The WooCommerce checkout](tutorial/01-woocommerce-checkout.md): the classic and block checkouts, Pay for order, Add payment method, orders, sessions, and the hooks along the way.
2. [Payment gateways](tutorial/02-payment-gateways.md): how gateways plug into WooCommerce, how card payments work, intents, 3-D Secure, tokens, express checkouts and subscriptions.
3. [How Fraud Protection works](tutorial/03-fraud-protection.md): what the plugin does, where it hooks in, and the path of one payment attempt through it.
4. [Gateway-specific flows](tutorial/04-gateway-specific-flows.md): where gateways take payments outside WooCommerce's forms, how the compatibility layers cover them, and native integrations.
5. [Reviewing changes](tutorial/05-reviewing-changes.md): lessons from past reviews, as case studies and reviewer questions.

The [glossary](tutorial/glossary.md) defines the terms used throughout.

## I'm integrating an extension

- [Integration guide](integration-guide.md): which native integration your gateway or extension needs, and how to build it.
- [Public API reference](api-reference.md): every public class, hook, browser method, script handle and request field.
- [Example integrations](../examples/README.md): two working plugins that the test suite runs.

## I'm working on the plugin

The internals reference documents everything that isn't public API:

- [Architecture](internals/architecture.md): source layout, startup, dependency injection, registration timing, and every hook the plugin uses.
- [Browser scripts](internals/browser-scripts.md): each script under `assets/js/` and how it handles the session ID.
- [Compatibility layers](internals/compatibility-layers.md): each gateway and extension layer, its hooks, and when it stands aside.
- [Data and storage](internals/data-and-storage.md): tables, options, session keys, order meta, cookies and schema management.
- [Admin and operations](internals/admin-and-operations.md): the settings app, REST routes, rules, notes, WP-CLI, logging and deployment.
- [Testing](internals/testing.md): test suites, helpers, and what to test by hand.

Also read [AGENTS.md](../AGENTS.md) for the rules every change follows, and the [README](../README.md) for setup, local testing and releases.

## Keeping these documents current

- Update the [API reference](api-reference.md) with every public API change, and the [integration guide](integration-guide.md) when the change affects how an extension integrates.
- Update the internals reference when you add or change a component, hook, script, stored value, REST route or command.
- Update the tutorial when a change alters a flow it describes, and add a case study to [chapter 5](tutorial/05-reviewing-changes.md) when a review catches something worth learning from.
- Don't describe how the fraud-detection service decides. These documents are public.
