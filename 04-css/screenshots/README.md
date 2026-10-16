# Screenshots

| File | Viewport | Shows |
| --- | --- | --- |
| `desktop.png` | 1920px, Chrome | `A A B / C D E / F G H`; A spans two columns |
| `mobile.png` | 427px, Chrome DevTools (Pixel 9 Pro) | A, D, B, C, G; E, F, H hidden |
| `narrow-320.png` | 320px, headless Chrome | Mobile layout at the smallest common phone width |

In all three, panel C's long heading wraps within its column with no horizontal
scroll. Even at 320px the `supercalifragilisticexpialidocious-00000000` token
fits on a line and breaks at its hyphen, so `overflow-wrap: anywhere` is a
safeguard for narrower containers rather than something these widths exercise.

## Recapture

From the repo root:

```bash
google-chrome --headless=new --hide-scrollbars --window-size=1280,900 \
  --screenshot="$PWD/04-css/screenshots/desktop.png" \
  "file://$PWD/04-css/index.html"

google-chrome --headless=new --hide-scrollbars --window-size=390,1400 \
  --screenshot="$PWD/04-css/screenshots/mobile.png" \
  "file://$PWD/04-css/index.html"
```
