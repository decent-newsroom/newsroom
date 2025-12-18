<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
    'quill' => [
        'version' => '2.0.3',
    ],
    'lodash-es' => [
        'version' => '4.17.21',
    ],
    'parchment' => [
        'version' => '3.0.0',
    ],
    'quill-delta' => [
        'version' => '5.1.0',
    ],
    'eventemitter3' => [
        'version' => '5.0.1',
    ],
    'fast-diff' => [
        'version' => '1.3.0',
    ],
    'lodash.clonedeep' => [
        'version' => '4.5.0',
    ],
    'lodash.isequal' => [
        'version' => '4.5.0',
    ],
    'quill/dist/quill.core.css' => [
        'version' => '2.0.3',
        'type' => 'css',
    ],
    'quill/dist/quill.snow.css' => [
        'version' => '2.0.3',
        'type' => 'css',
    ],
    'es-module-shims' => [
        'version' => '2.0.10',
    ],
    'nostr-tools' => [
        'version' => '2.17.0',
    ],
    '@noble/curves/secp256k1' => [
        'version' => '1.2.0',
    ],
    '@noble/hashes/utils' => [
        'version' => '1.3.1',
    ],
    '@noble/hashes/sha256' => [
        'version' => '1.3.1',
    ],
    '@scure/base' => [
        'version' => '1.1.1',
    ],
    '@noble/ciphers/aes' => [
        'version' => '0.5.3',
    ],
    '@noble/ciphers/chacha' => [
        'version' => '0.5.3',
    ],
    '@noble/ciphers/utils' => [
        'version' => '0.5.3',
    ],
    '@noble/hashes/hkdf' => [
        'version' => '1.3.1',
    ],
    '@noble/hashes/hmac' => [
        'version' => '1.3.1',
    ],
    '@noble/hashes/crypto' => [
        'version' => '1.3.1',
    ],
    'nostr-tools/nip46' => [
        'version' => '2.17.0',
    ],
    'chart.js/auto' => [
        'version' => '4.5.0',
    ],
    '@kurkle/color' => [
        'version' => '0.3.4',
    ],
    'katex' => [
        'version' => '0.16.25',
    ],
    'katex/dist/contrib/auto-render.mjs' => [
        'version' => '0.16.25',
    ],
    'katex/dist/katex.min.css' => [
        'version' => '0.16.25',
        'type' => 'css',
    ],
    'katex/dist/katex.min.js' => [
        'version' => '0.16.25',
    ],
    'prism-react' => [
        'version' => '1.0.2',
    ],
    'prism-redux' => [
        'version' => '1.0.2',
    ],
    'react' => [
        'version' => '15.5.4',
    ],
    'recompose' => [
        'version' => '0.22.0',
    ],
    'object-assign' => [
        'version' => '4.1.1',
    ],
    'fbjs/lib/invariant' => [
        'version' => '0.8.12',
    ],
    'fbjs/lib/warning' => [
        'version' => '0.8.12',
    ],
    'fbjs/lib/emptyFunction' => [
        'version' => '0.8.12',
    ],
    'fbjs/lib/emptyObject' => [
        'version' => '0.8.12',
    ],
    'prop-types/factory' => [
        'version' => '15.5.7',
    ],
    'fbjs/lib/shallowEqual' => [
        'version' => '0.8.8',
    ],
    'hoist-non-react-statics' => [
        'version' => '1.2.0',
    ],
    'change-emitter' => [
        'version' => '0.1.2',
    ],
    'symbol-observable' => [
        'version' => '1.0.4',
    ],
    'prismjs' => [
        'version' => '1.30.0',
    ],
    'prismjs/themes/prism.min.css' => [
        'version' => '1.30.0',
        'type' => 'css',
    ],
];
