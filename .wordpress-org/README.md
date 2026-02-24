# WordPress.org Plugin Assets

This directory contains assets for the WordPress.org plugin directory listing.
These files are deployed to SVN by the `release.yml` workflow via the
`ASSETS_DIR` configuration in `10up/action-wordpress-plugin-deploy`.

## Required Assets

| File                  | Dimensions    | Purpose                     |
| --------------------- | ------------- | --------------------------- |
| `banner-772x250.png`  | 772 x 250 px  | Plugin page banner          |
| `banner-1544x500.png` | 1544 x 500 px | HiDPI banner (2x, optional) |
| `icon-128x128.png`    | 128 x 128 px  | Search results icon         |
| `icon-256x256.png`    | 256 x 256 px  | HiDPI icon (2x, optional)   |

## Screenshots

Screenshots are referenced in `readme.txt` under `== Screenshots ==`.
Name them sequentially: `screenshot-1.png`, `screenshot-2.png`, etc.

| File               | Description                               |
| ------------------ | ----------------------------------------- |
| `screenshot-1.png` | Connection page (disconnected state)      |
| `screenshot-2.png` | Connected state with organization details |
| `screenshot-3.png` | Settings tab                              |
| `screenshot-4.png` | WooCommerce integration tab               |

## Guidelines

- Use PNG format for all assets
- Banners should have no text that would be hard to read at small sizes
- Icons should be recognizable at 128x128
- Screenshots should show realistic data, not placeholder content
- See: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
