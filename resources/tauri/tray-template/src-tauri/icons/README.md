# Icons Directory

This directory should contain the tray icon for the RMM agent.

## Required Icon

- `icon.ico` - System tray icon for Windows (recommended sizes: 16x16, 32x32, 48x48)

You can also add platform-specific icons:
- `icon.png` - For Linux/macOS tray
- `icon.icns` - For macOS app bundle

## Creating Icons

You can use tools like:
- ImageMagick: `convert icon.png -define icon:auto-resize=16,32,48 icon.ico`
- Online converters: converticon.com, cloudconvert.com
- Icon editors: GIMP, Inkscape, etc.

## Placeholder

If you don't have a custom icon, you can use a simple colored square or download a free icon from:
- Iconoir (iconoir.com)
- Lucide Icons (lucide.dev)
- Material Icons (fonts.google.com/icons)
