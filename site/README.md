# Sourdough front page

A static page: plain HTML, CSS and a little vanilla JS, no build step. Open `index.html` in a browser or serve
`site/` from anywhere (Citadel, OpenShift, Coolify, raw Docker, a laptop).

- world: a bakery at dawn. Flour-dusted paper, a linen proofing couche, a cooling rack, a recipe card, the
  feeding schedule chalked above the bench, and the shop shelves holding the rest of the stack.
- type: **Caprasimo** 400 (SIL OFL 1.1, self-hosted in `assets/fonts/` from `@fontsource/caprasimo`, licence in
  `OFL-caprasimo.txt`) for display, and **Kalam** 400/700 (SIL OFL 1.1, `OFL-kalam.txt`) for the baker's hand;
  body text is Lato from WayneUI.
- base: WayneUI 2.0.8, copied into `assets/wayne-ui/` from its documented static path (tokens, Lato, container,
  skip link, tabs). `assets/sourdough.css` sits on top, unlayered.
- brand: the ChatGPT mark and favicons in `brand/`; never redrawn. Other parts' marks are in `assets/marks/`.
- magic: the proof. Scroll is the clock: the dough rises past its gold band while the ingredients go in (WayneUI,
  SESAME, HELMET, PROCTOR), then it is turned out, cut and shaped into the five loaves, one per permutation. On the
  cooling rack, scoring a loaf opens its crumb to show what's baked in. In the hero, "Feed the starter" feeds it.
- reduced motion: the finished bake. Every loaf out, every log entry written, every crumb open, nothing moving.

Screenshots are in `screenshots/` (desktop 1440, mobile 390, reduced motion, a motion GIF, and mobile crops per
section in `screenshots/sections/`).
