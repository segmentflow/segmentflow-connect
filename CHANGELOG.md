# Changelog

## [1.2.0](https://github.com/segmentflow/segmentflow-connect/compare/v1.1.1...v1.2.0) (2026-03-13)


### Features

* add WooCommerce integration enable/disable toggle in admin settings ([f85d373](https://github.com/segmentflow/segmentflow-connect/commit/f85d37316f125b5b318101bc9c6c5c2833e7bc55))


### Bug Fixes

* strip null userId from track events for anonymous visitors ([3a7802d](https://github.com/segmentflow/segmentflow-connect/commit/3a7802d7ae400afe498a52db1534a347e6274d4f))

## [1.1.1](https://github.com/segmentflow/segmentflow-connect/compare/v1.1.0...v1.1.1) (2026-03-12)


### Bug Fixes

* remove BUILD_DIR to respect .distignore on SVN deploy ([668a896](https://github.com/segmentflow/segmentflow-connect/commit/668a896cdbfcc0d9071a06ea638740655c152ecf))
* sync version numbers and add release-please markers ([3bb3c1e](https://github.com/segmentflow/segmentflow-connect/commit/3bb3c1eed76eac362255c0206466c5acee6766dd))

## [1.1.0](https://github.com/segmentflow/segmentflow-connect/compare/v1.0.6...v1.1.0) (2026-03-12)


### Features

* add cart_item_key to WooCommerce cart data for Blocks support ([c88c7da](https://github.com/segmentflow/segmentflow-connect/commit/c88c7da0bd207a10e2f0bfd9a54e31badbd4f0ef))
* add client-side e-commerce tracking and form submission tracking ([787372f](https://github.com/segmentflow/segmentflow-connect/commit/787372fa35fad271214cd1f3e0e6a422ea8b4e56))
* add Forms tab with site URL copy, dashboard links, and setup guide ([5ee8dbd](https://github.com/segmentflow/segmentflow-connect/commit/5ee8dbd41288fdc7b44403ca6f97c704af67d83e))
* add independent dashboard host config, admin UI polish, and project assets ([515e511](https://github.com/segmentflow/segmentflow-connect/commit/515e511ad20898cfd1a661def45816e6f609aea5))
* add lead magnets row to admin connection page and bump version to 1.0.5 ([6f06fa3](https://github.com/segmentflow/segmentflow-connect/commit/6f06fa3796f649d748741bcac38854bd6f60000b))
* add PHP server-side event hooks for cart mutations and identity capture ([a9b11bd](https://github.com/segmentflow/segmentflow-connect/commit/a9b11bd73af1a3c114de30f0a18094323f3992cb))
* add unified identity cookie (sf_id) bridging PHP and SDK ([487aa58](https://github.com/segmentflow/segmentflow-connect/commit/487aa5874a208717c72928138238203f5a27422c))
* add WordPress.org directory assets ([e87493a](https://github.com/segmentflow/segmentflow-connect/commit/e87493a5930af3623ec269259d95f0585ff97a75))
* add wp-env local dev environment and update API host to api.cloud.segmentflow.ai ([6707a95](https://github.com/segmentflow/segmentflow-connect/commit/6707a95027a8325808e21f64e2177a490c745617))
* implement client-side connection polling with connecting UI state ([49fb738](https://github.com/segmentflow/segmentflow-connect/commit/49fb7387d593433ccfb41e07f2c18e4327825ee0))
* scaffold WordPress plugin repo with tooling and class stubs ([e6e5ade](https://github.com/segmentflow/segmentflow-connect/commit/e6e5ade7a0028f6ae5c268ab2a2b661d73133e06))


### Bug Fixes

* address WordPress plugin review requirements ([4893a47](https://github.com/segmentflow/segmentflow-connect/commit/4893a47603feadc4e4db557670ae721615ad66a8))
* correct API endpoint paths and uninstall write key handling ([77933a9](https://github.com/segmentflow/segmentflow-connect/commit/77933a944624c991cee86f34b3b97bc17b73f7fb))
* correct lifecycle platform comparison and add wp_unslash to admin tab ([8505053](https://github.com/segmentflow/segmentflow-connect/commit/85050533d7ec5d2591da3e290b09297083906cdf))
* correct SDK CDN URL and identify() call signature ([461ba17](https://github.com/segmentflow/segmentflow-connect/commit/461ba17643b58100bded0d71f8394ca0b84ef389))
* expose integrationContext on window for SDK WooCommerce plugin ([660703d](https://github.com/segmentflow/segmentflow-connect/commit/660703dc811bd0b8877407c73c395cd926781988))
* register settings on admin_init to fix options allowlist error ([0b327ad](https://github.com/segmentflow/segmentflow-connect/commit/0b327ad2746ca4ca848d017a029d9fe1acd4f1bd))
* remove client-side order tracking in favor of webhook-based order events ([37f53fa](https://github.com/segmentflow/segmentflow-connect/commit/37f53fafc5bf971bf3f05bca64223e0036604f79))
* remove dead async strategy from SDK enqueue and add test coverage ([4432dbf](https://github.com/segmentflow/segmentflow-connect/commit/4432dbfd4cc9254cc5349bfc83d3096d2aa969da))
* remove inline comment from Stable tag in readme.txt ([56aa352](https://github.com/segmentflow/segmentflow-connect/commit/56aa35276a3768dc2620042a9c3141b3ae58118f))
* remove inline comment from Version header in plugin file ([34d4ada](https://github.com/segmentflow/segmentflow-connect/commit/34d4ada20cb604ded4bb1a846a5319f03015ef40))
* skip email trait from PHP identify on checkout page ([2d47f45](https://github.com/segmentflow/segmentflow-connect/commit/2d47f452e0b982ef795a4c71737ed31245c1a793))
* update default API host from api.cloud.segmentflow.ai to api.segmentflow.ai ([4785346](https://github.com/segmentflow/segmentflow-connect/commit/4785346b7058a66b7be17037c84f3761858f4c35))
