# Sourdough brand files

**The mark is a starter jar: the starter has risen into a dome, well above the gold band that marks where it
began.** Bakers put a band on the jar to see whether a starter is alive. Sourdough's starters are the same: you
feed them, and you can see that they grew.

## Colours
| Role | Colour |
|---|---|
| Jar and starter, tile | Wayne State green `#0C5449` |
| The band (the only gold) | Wayne State gold `#FFCC33` |
| Jar and starter on the tile or on dark backgrounds | White `#FFFFFF` |
| Site accent only, never in the mark | Crust `#B69018` (WayneUI `gold.800`) |

Gold never sits behind white text.

## Files
| File | Use |
|---|---|
| `sourdough-mark.svg` | The mark on light backgrounds: green jar and starter, gold band |
| `sourdough-mark-white.svg` | The mark on dark or green backgrounds: white jar and starter, gold band |
| `sourdough-mark-mono.svg` | One colour (`currentColor`), for print and single-ink use |
| `sourdough-tile.svg` | The app icon: a green rounded tile with the white jar |
| `sourdough-favicon.svg` | The tile simplified for 32 and 64 px: thicker walls and a thicker band |
| `sourdough-favicon-16.svg` | Hand-hinted on the 16 px grid. One unit is one pixel |
| `sourdough-avatar-512.png` | The GitLab project avatar and the Open Graph image base |
| `sourdough-mark-1024.png`, `sourdough-mark-white-1024.png` | Raster marks for slides and documents |
| `favicon-16.png`, `favicon-32.png`, `favicon-64.png`, `favicon.ico` | Browser favicons; the `.ico` holds all three |

## Geometry
The SVGs are drawn from exact geometry, not traced.
- **The jar:** an open U, 280 units wide and 280 tall on a 512 grid, with walls 30 thick, outer bottom corners of
  radius 70 and round caps on top.
- **The starter:** 18 units inside the walls, with a half-ellipse dome 80 tall. Its top sits just below the rim.
- **The band:** 34 units tall with round ends, 16 wider than the jar on each side. Jar and starter stop 10 units
  clear of it, so the band still reads when the mark is one colour.

## Rendering
The PNGs were rendered with Inkscape 1.4, for example
`inkscape sourdough-tile.svg --export-type=png --export-filename=sourdough-avatar-512.png -w 512 -h 512`. The `.ico`
was built with ImageMagick 7: `magick favicon-16.png favicon-32.png favicon-64.png favicon.ico`.
