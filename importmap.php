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
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
    '@noble/hashes/utils' => [
        'version' => '1.3.1',
    ],
    '@noble/hashes/sha256' => [
        'version' => '1.3.1',
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
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    'quill' => [
        'version' => '2.0.3',
    ],
    'lodash-es' => [
        'version' => '4.17.22',
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
        'version' => '2.8.0',
    ],
    'nostr-tools' => [
        'version' => '2.19.4',
    ],
    '@noble/curves/secp256k1' => [
        'version' => '1.2.0',
    ],
    '@scure/base' => [
        'version' => '1.1.1',
    ],
    '@noble/ciphers/aes' => [
        'version' => '2.1.1',
    ],
    '@noble/ciphers/chacha' => [
        'version' => '0.5.3',
    ],
    '@noble/ciphers/utils' => [
        'version' => '0.5.3',
    ],
    'nostr-tools/nip46' => [
        'version' => '2.19.4',
    ],
    'chart.js/auto' => [
        'version' => '4.5.1',
    ],
    '@kurkle/color' => [
        'version' => '0.4.0',
    ],
    'katex' => [
        'version' => '0.16.27',
    ],
    'katex/dist/contrib/auto-render.mjs' => [
        'version' => '0.16.27',
    ],
    'katex/dist/katex.min.css' => [
        'version' => '0.16.27',
        'type' => 'css',
    ],
    'katex/dist/katex.min.js' => [
        'version' => '0.16.27',
    ],
    'prism-react' => [
        'version' => '1.0.2',
    ],
    'prism-redux' => [
        'version' => '1.0.2',
    ],
    'react' => [
        'version' => '19.2.3',
    ],
    'recompose' => [
        'version' => '0.30.0',
    ],
    'object-assign' => [
        'version' => '4.1.1',
    ],
    'fbjs/lib/invariant' => [
        'version' => '3.0.5',
    ],
    'fbjs/lib/warning' => [
        'version' => '3.0.5',
    ],
    'fbjs/lib/emptyFunction' => [
        'version' => '3.0.5',
    ],
    'fbjs/lib/emptyObject' => [
        'version' => '3.0.5',
    ],
    'prop-types/factory' => [
        'version' => '15.8.1',
    ],
    'fbjs/lib/shallowEqual' => [
        'version' => '3.0.5',
    ],
    'hoist-non-react-statics' => [
        'version' => '3.3.2',
    ],
    'change-emitter' => [
        'version' => '0.1.6',
    ],
    'symbol-observable' => [
        'version' => '4.0.0',
    ],
    'prismjs' => [
        'version' => '1.30.0',
    ],
    'prismjs/themes/prism.min.css' => [
        'version' => '1.30.0',
        'type' => 'css',
    ],
    'codemirror' => [
        'version' => '6.0.2',
    ],
    '@codemirror/lang-markdown' => [
        'version' => '6.5.0',
    ],
    '@codemirror/theme-one-dark' => [
        'version' => '6.1.3',
    ],
    '@codemirror/view' => [
        'version' => '6.39.11',
    ],
    '@codemirror/state' => [
        'version' => '6.5.4',
    ],
    '@codemirror/language' => [
        'version' => '6.12.1',
    ],
    '@codemirror/commands' => [
        'version' => '6.10.1',
    ],
    '@codemirror/search' => [
        'version' => '6.6.0',
    ],
    '@codemirror/autocomplete' => [
        'version' => '6.20.0',
    ],
    '@codemirror/lint' => [
        'version' => '6.9.2',
    ],
    '@lezer/markdown' => [
        'version' => '1.6.3',
    ],
    '@codemirror/lang-html' => [
        'version' => '6.4.11',
    ],
    '@lezer/common' => [
        'version' => '1.5.0',
    ],
    '@lezer/highlight' => [
        'version' => '1.2.3',
    ],
    'style-mod' => [
        'version' => '4.1.3',
    ],
    'w3c-keyname' => [
        'version' => '2.2.8',
    ],
    'crelt' => [
        'version' => '1.0.6',
    ],
    '@marijn/find-cluster-break' => [
        'version' => '1.0.2',
    ],
    '@lezer/html' => [
        'version' => '1.3.13',
    ],
    '@codemirror/lang-css' => [
        'version' => '6.3.1',
    ],
    '@codemirror/lang-javascript' => [
        'version' => '6.2.4',
    ],
    '@lezer/lr' => [
        'version' => '1.4.7',
    ],
    '@lezer/css' => [
        'version' => '1.3.0',
    ],
    '@lezer/javascript' => [
        'version' => '1.5.4',
    ],
    '@codemirror/lang-json' => [
        'version' => '6.0.2',
    ],
    '@lezer/json' => [
        'version' => '1.0.3',
    ],
    '@noble/hashes/sha2.js' => [
        'version' => '2.0.1',
    ],
    '@noble/hashes/utils.js' => [
        'version' => '2.0.1',
    ],
    '@noble/hashes/hmac.js' => [
        'version' => '2.0.1',
    ],
    '@babel/runtime/helpers/esm/extends' => [
        'version' => '7.0.0',
    ],
    '@babel/runtime/helpers/esm/inheritsLoose' => [
        'version' => '7.0.0',
    ],
    'react-lifecycles-compat' => [
        'version' => '3.0.4',
    ],
    '@babel/runtime/helpers/esm/objectWithoutPropertiesLoose' => [
        'version' => '7.0.0',
    ],
    'react-is' => [
        'version' => '16.12.0',
    ],
    'nostr-tools/utils' => [
        'version' => '2.19.4',
    ],
    'nostr-tools/nip44' => [
        'version' => '2.19.4',
    ],
];
