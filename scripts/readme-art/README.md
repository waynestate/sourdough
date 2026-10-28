# Sourdough README art kit

Regenerates the README images under `docs/readme/` from this repo's own static
front page (`site/`) and brand files. Nothing is drawn by hand: the hero and
the gallery are screenshots of the real page, the proofing diagram is built
from the page's own proofing log and feeding schedule, the cast is the marks
resized, and the badges read their facts from `composer.json` and the README.

## Run

Windows PowerShell and Linux/macOS sh, side by side:

| Windows PowerShell | Linux / macOS sh |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The script resolves every path from its own location, so it works from any
folder on any OS. It opens `site/index.html` as a `file://` URL: no server, no
network. Same inputs, same outputs.

## What it makes

- `docs/readme/hero.jpg`: the front-page hero, header and blurb hidden.
- `docs/readme/proof.svg`: dough rising in a proofing tub past its gold band
  as the real parts go in one by one (animated; under
  `prefers-reduced-motion` it is the finished, risen still).
- `docs/readme/gallery.jpg`: the five loaves on the bench over the feeding
  schedule.
- `docs/readme/board.jpg`: the brand board, re-encoded.
- `docs/readme/cast/*.png`: one 96x96 mark per stack part.
- `docs/readme/badges/*.svg`: `php`, `licence` and `exit-codes`, made locally
  by `badge-maker`.

Every file must stay at or under 300 KB, and no captured text may mention an
internal host or platform; the script exits 1 if either rule breaks.

If a badge ever looks wrong, the fact it was read from is wrong, not the
badge. A starter that lies about its own age goes flat.
