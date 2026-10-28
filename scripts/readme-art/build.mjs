#!/usr/bin/env node
/* ============================================================================
   Sourdough README art kit.

   Renders the README images from this repo's own static front page (site/)
   and brand files, and writes them under docs/readme/. Nothing is drawn by
   hand: the hero and the gallery are screenshots of the real page, the
   proofing diagram is built from the page's own proofing log and feeding
   schedule, the cast is the marks resized (WayneUI composed on its green
   tile), and the badges read their facts from composer.json and the README.

   Run from any folder (every path resolves from this file's own location):

     npm --prefix scripts/readme-art ci
     npx --prefix scripts/readme-art playwright install chromium
     node scripts/readme-art/build.mjs

   The page is opened as a file:// URL: no server, no network.
   ========================================================================== */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/* ------------------------------------------------------------- paths, limits */

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const outDir = path.join(repoRoot, "docs", "readme");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024; // every file in docs/readme/ stays at or under this
const FORBIDDEN = /noug[a]t|snu[.]gs|citadel/i; // internal names never ship in art

// Sourdough's own palette, from site/assets/sourdough.css.
const C = {
  green: "#0c5449",
  greenDeep: "#073a32",
  gold: "#ffcc33",
  goldChalk: "#ffd866",
  flour: "#fffdf8",
  cream: "#f4f1ea",
  kraft: "#e8d4ab",
  wood: "#9a6a3c",
  slate: "#1f2c28",
  chalk: "#eeece4",
  chalkDim: "#b3bfb9",
};

const outputs = [];

function record(rel, buf) {
  const abs = path.join(outDir, rel);
  mkdirSync(path.dirname(abs), { recursive: true });
  writeFileSync(abs, buf);
  outputs.push({ rel, bytes: buf.length });
  console.log(`  ${rel}  ${buf.length} bytes`);
}

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}

function esc(s) {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

function checkClean(text, what) {
  if (FORBIDDEN.test(text)) fail(`forbidden text matched ${FORBIDDEN} in ${what}`);
}

/* ------------------------------------------------------------- 1. cast strip */

// One 96x96 PNG per stack part (shown at 48 px in the README). The marks are
// copied and resized only, never redrawn. WayneUI is composed instead: its
// reverse mark (white frame, gold tab) set on a Wayne State green tile, so it
// reads as a sibling of the other thirteen.
const CAST = [
  "creed",
  "proctor",
  "sesame",
  "helmet",
  "sourdough",
  "squire",
  "preflight",
  "pinstripe",
  "booster",
  "rollcall",
  "drawbridge",
  "advisor",
  "weld",
];

function castSource(name) {
  // Sourdough's own mark lives in the brand folder, not in assets/marks.
  if (name === "sourdough") return path.join(siteDir, "brand", "sourdough-avatar-256.png");
  return path.join(siteDir, "assets", "marks", `${name}.png`);
}

// The WayneUI tile: the reverse mark, traced from WayneUI's approved artwork,
// set on a green rounded tile. The traced paths are placed unchanged: no edits
// to any d= value, fill, or the mark's own viewBox. The visible ink spans
// 1546 x 1551 of the 1980 x 1989 viewBox and is centred in it, so this scale
// makes the mark 352 px tall on the 512 tile (about 69%), matching the square
// marks WELD and SESAME.
function wayneuiTileSvg() {
  const raw = readFileSync(path.join(scriptDir, "src", "wayneui-mark-reverse.svg"), "utf8");
  const open = raw.indexOf("<svg");
  const openEnd = raw.indexOf(">", open) + 1;
  const close = raw.lastIndexOf("</svg>");
  if (open < 0 || openEnd < 2 || close < openEnd) fail("wayneui-mark-reverse.svg: no <svg> element");
  // Everything between the opening tag and the final </svg>, verbatim (the
  // file's own line endings included, so the paths stay byte-identical).
  const inner = raw.slice(openEnd, close).replace(/^\r?\n/, "");
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">
  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>
  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">
${inner}</svg>
</svg>
`;
}

async function buildCast() {
  console.log("cast:");
  const tile = Buffer.from(wayneuiTileSvg(), "utf8");
  record("cast/wayneui.svg", tile);
  const tilePng = await sharp(tile, { density: 300 })
    .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  record("cast/wayneui.png", tilePng);
  const stale = path.join(castDir, "wayneui-dark.png");
  if (existsSync(stale)) {
    rmSync(stale);
    console.log("  removed cast/wayneui-dark.png (no longer generated)");
  }
  for (const name of CAST) {
    const src = castSource(name);
    if (!existsSync(src)) {
      console.log(`  skip cast/${name}.png: missing source ${path.relative(repoRoot, src)}`);
      continue;
    }
    const png = await sharp(readFileSync(src))
      .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png({ palette: true, quality: 85, compressionLevel: 9 })
      .toBuffer();
    record(`cast/${name}.png`, png);
  }
}

/* ---------------------------------------------------------------- 2. badges */

// Facts are read from composer.json and the README, never typed: a badge that
// lies is worse than no badge at all.
function buildBadges() {
  console.log("badges:");
  const composer = JSON.parse(readFileSync(path.join(repoRoot, "composer.json"), "utf8"));
  const readme = readFileSync(path.join(repoRoot, "README.md"), "utf8");
  // The CLI's exit-code contract: "Exit codes: `0` done, `1` refused ..., `2` tool error."
  const exits = /Exit codes: `0` [^,]+, `1` [^,]+, `2` /.exec(readme);
  const codes = exits ? [...exits[0].matchAll(/`(\d)`/g)].map((m) => m[1]) : [];
  const defs = [
    { key: "php", label: "php", message: composer.require.php },
    { key: "license", label: "licence", message: composer.license },
    { key: "exit-codes", label: "exit codes", message: codes.join(" · ") },
  ];
  for (const d of defs) {
    if (!d.message) fail(`no fact to badge for "${d.key}"`);
    const svg = makeBadge({
      label: d.label,
      message: d.message,
      labelColor: C.green,
      color: C.kraft,
      style: "flat-square",
    });
    record(`badges/${d.key}.svg`, svg);
  }
}

/* ------------------------------------------------------------- 3. the page */

async function openPage(browser) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 2, // crisp images
    reducedMotion: "reduce", // the finished bake: every loaf out, every word on the page
  });
  const page = await context.newPage();
  await page.goto(pathToFileURL(path.join(siteDir, "index.html")).href, {
    waitUntil: "networkidle",
  });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

async function shot(page, selector, what) {
  checkClean(await page.locator(selector).innerText(), what);
  return page.locator(selector).screenshot();
}

/* ------------------------------------------------------------------ 4. hero */

async function buildHero(page) {
  console.log("hero:");
  // Hide the chrome that must not appear in the README art. The blurb names
  // an internal platform, so it stays out of the image; the title, tagline,
  // recipe command and jar are the hero.
  await page.addStyleTag({
    content: ".site-header, .hero__blurb { display: none !important; }",
  });
  const png = await shot(page, ".hero", "hero");
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("hero.jpg", jpg);
}

/* --------------------------------------------- 5. the proof (signature SVG) */

const dataUri = (file, mime) => `data:${mime};base64,${readFileSync(file).toString("base64")}`;

// keyframes from [t seconds, value] pairs over a loop of `total` seconds
function frames(name, total, pts, prop) {
  const lines = pts.map(([t, v]) => `${((t / total) * 100).toFixed(2)}% { ${prop(v)} }`);
  return `@keyframes ${name} { ${lines.join(" ")} }`;
}

// The signature: dough rising in a proofing tub past its gold band while the
// real parts go in one by one. Every word is read from the page's own proofing
// log, recipe card and feeding schedule; the marks are the cast PNGs. Pure SVG
// and CSS keyframes: no script, no external refs. Reduced motion gets the
// finished picture: the dough well past the band and every line written.
function buildProofSvg(d) {
  const W = 1280;
  const H = 480;
  const T = 15; // loop length, seconds

  // --- the tub
  const tubX = 90, tubW = 200, tubTop = 100, tubBot = 360;
  const inX = tubX + 8, inW = tubW - 16, inBot = tubBot - 6;
  const bandY = 252; // the gold band: where it started
  const edge0 = bandY + 10; // dough edge sits on the band at the start
  const x2 = 170; // the 2x line
  const finalOff = -120; // well past 2x
  // dough offsets reached after each drop (px, negative = up)
  const offs = [-18, -38, -58, -78, -96];

  // --- the five drops: the parts the log and recipe card name
  const parts = [
    { name: "WayneUI", cx: 150, at: 0.9 },
    { name: "SESAME", cx: 200, at: 2.1 },
    { name: "HELMET", cx: 240, at: 3.3 },
    { name: "PROCTOR", cx: 175, at: 4.5 },
    { name: "PREFLIGHT", cx: 225, at: 5.5 },
  ];
  const fall = 0.8;
  const tile = 52;
  const peakAt = (k) => edge0 - 12 + (k === 0 ? 0 : offs[k - 1]);

  const css = [];
  css.push(`
    @font-face { font-family: "SDCaprasimo"; src: url(${dataUri(path.join(siteDir, "assets/fonts/caprasimo-latin-400-normal.woff2"), "font/woff2")}) format("woff2"); font-weight: 400; }
    @font-face { font-family: "SDKalam"; src: url(${dataUri(path.join(siteDir, "assets/fonts/kalam-latin-700-normal.woff2"), "font/woff2")}) format("woff2"); font-weight: 700; }
    @font-face { font-family: "SDLato"; src: url(${dataUri(path.join(siteDir, "assets/wayne-ui/fonts/lato-400-latin.woff2"), "font/woff2")}) format("woff2"); font-weight: 400; }
    @font-face { font-family: "SDLato"; src: url(${dataUri(path.join(siteDir, "assets/wayne-ui/fonts/lato-700-latin.woff2"), "font/woff2")}) format("woff2"); font-weight: 700; }
    .display { font-family: SDCaprasimo, "Cooper Black", Georgia, serif; }
    .hand { font-family: SDKalam, "Comic Sans MS", cursive; font-weight: 700; }
    .body { font-family: SDLato, Lato, "Segoe UI", Helvetica, Arial, sans-serif; }
    .mono { font-family: Consolas, Menlo, "DejaVu Sans Mono", monospace; }
    .dough, .row, .tile { animation-duration: ${T}s; animation-iteration-count: infinite; animation-timing-function: ease-in-out; }
  `);

  // dough: rises a step after each part lands, then the long rise to doubled
  const dp = [[0, 0]];
  parts.forEach((p, i) => {
    const land = p.at + fall;
    dp.push([land + 0.05, i === 0 ? 0 : offs[i - 1]]);
    dp.push([land + 0.7, offs[i]]);
  });
  dp.push([7.3, offs[4]], [8.6, finalOff], [13.4, finalOff], [14.6, 0], [T, 0]);
  css.push(frames("rise", T, dp, (v) => `transform: translateY(${v}px);`));
  css.push(`.dough { animation-name: rise; }`);

  // tiles fall into the tub, then are gone
  parts.forEach((p, i) => {
    const y0 = 20;
    const y1 = peakAt(i) - 18;
    const dy = y1 - y0;
    const pts = [
      [0, { y: 0, o: 0, s: 1 }],
      [p.at, { y: 0, o: 0, s: 1 }],
      [p.at + 0.08, { y: 0, o: 1, s: 1 }],
      [p.at + fall - 0.12, { y: dy * 0.9, o: 1, s: 0.9 }],
      [p.at + fall, { y: dy, o: 0, s: 0.7 }],
      [T, { y: dy, o: 0, s: 0.7 }],
    ];
    css.push(frames(`drop${i}`, T, pts, ({ y, o, s }) => `transform: translateY(${y}px) scale(${s}); opacity: ${o};`));
    css.push(`.tile${i} { animation-name: drop${i}; animation-timing-function: cubic-bezier(.5,0,.9,.6); transform-origin: ${p.cx}px ${y0 + tile / 2}px; }`);
  });

  // log rows light up as their step happens
  const rowTimes = [0.3, parts[0].at + fall, parts[1].at + fall, parts[2].at + fall, parts[3].at + fall, 8.0, 9.4];
  d.rows.forEach((_, i) => {
    const t = rowTimes[i];
    const dim = { o: 0.28, x: 8 };
    const lit = { o: 1, x: 0 };
    const pts = [[0, dim], [t, dim], [t + 0.5, lit], [13.6, lit], [14.5, dim], [T, dim]];
    css.push(frames(`row${i}`, T, pts, ({ o, x }) => `opacity: ${o}; transform: translateX(${x}px);`));
    css.push(`.row${i} { animation-name: row${i}; }`);
  });

  css.push(`
    @media (prefers-reduced-motion: reduce) {
      .dough, .row, .tile { animation: none !important; }
      .dough { transform: translateY(${finalOff}px); }
      .row { opacity: 1 !important; transform: none !important; }
      .tile { opacity: 0 !important; }
    }
  `);

  // --- the log rows
  const rowX = 400, rowY0 = 186, pitch = 33;
  const rows = d.rows
    .map((r, i) => {
      const y = rowY0 + i * pitch;
      return `  <g class="row row${i}" opacity=".28">
    <circle cx="${rowX + 8}" cy="${y - 5}" r="5" fill="${C.gold}"/>
    <text class="hand" x="${rowX + 26}" y="${y}" font-size="18" fill="${C.goldChalk}">${esc(r.time)}</text>
    <text class="body" x="${rowX + 88}" y="${y}" font-size="15" fill="${C.cream}"><tspan font-weight="700" fill="${C.flour}">${esc(r.title)}</tspan> ${esc(r.text)}</text>
  </g>`;
    })
    .join("\n");

  // --- the tiles
  const tiles = parts
    .map((p, i) => {
      const f = path.join(castDir, `${p.name.toLowerCase()}.png`);
      if (!existsSync(f)) fail(`proof needs cast/${p.name.toLowerCase()}.png (build the cast first)`);
      return `  <image class="tile tile${i}" opacity="0" x="${p.cx - tile / 2}" y="20" width="${tile}" height="${tile}" href="${dataUri(f, "image/png")}"/>`;
    })
    .join("\n");

  // --- dough: a dome on a tall body, clipped to the tub
  const dy0 = edge0;
  const doughPath = `M${inX} ${inBot + 120} L${inX} ${dy0} Q${tubX + tubW / 2} ${dy0 - 26} ${inX + inW} ${dy0} L${inX + inW} ${inBot + 120} Z`;
  const bubbles = [[140, 300, 5], [190, 330, 7], [236, 292, 4], [262, 330, 6], [170, 272, 3.5], [225, 318, 3.5], [120, 340, 4], [250, 274, 3]]
    .map(([x, y, r]) => `<circle cx="${x}" cy="${y}" r="${r}" fill="#fff7e4" opacity=".55"/>`)
    .join("");

  const desc = `The proof: a tub of dough rises past its gold starting band as the parts go in one by one. ${d.rows.map((r) => `${r.time} ${r.title} ${r.text}`).join(" ")} ${d.feed.when}: ${d.feed.cmd}. ${d.feed.text}`;

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-labelledby="proof-title proof-desc">
  <title id="proof-title">The proof: dough rising in a tub as the stack goes in</title>
  <desc id="proof-desc">${esc(desc)}</desc>
  <style>${css.join("\n")}</style>
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${C.green}"/><stop offset="1" stop-color="${C.greenDeep}"/></linearGradient>
    <radialGradient id="dawn" cx="${tubX + tubW / 2}" cy="${tubBot + 30}" r="340" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="${C.gold}" stop-opacity=".30"/><stop offset=".55" stop-color="${C.gold}" stop-opacity=".07"/><stop offset="1" stop-color="${C.gold}" stop-opacity="0"/></radialGradient>
    <linearGradient id="g-dough" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fbefd6"/><stop offset="1" stop-color="#e2c690"/></linearGradient>
    <clipPath id="c-tub"><rect x="${inX}" y="${tubTop + 6}" width="${inW}" height="${inBot - tubTop - 6}" rx="18"/></clipPath>
    <clipPath id="c-card"><rect width="${W}" height="${H}" rx="20"/></clipPath>
  </defs>
  <rect width="${W}" height="${H}" rx="20" fill="url(#bg)"/>
  <g clip-path="url(#c-card)"><rect width="${W}" height="${H}" fill="url(#dawn)"/></g>

  <!-- the bench -->
  <rect x="40" y="${tubBot + 4}" width="320" height="14" rx="5" fill="${C.wood}"/>

  <!-- the tub, with the dough inside -->
  <rect x="${tubX}" y="${tubTop}" width="${tubW}" height="${tubBot - tubTop}" rx="24" fill="rgba(7,58,50,.55)"/>
  <g clip-path="url(#c-tub)">
    <g class="dough"><path d="${doughPath}" fill="url(#g-dough)"/>${bubbles}</g>
  </g>
  <rect x="${tubX}" y="${tubTop}" width="${tubW}" height="${tubBot - tubTop}" rx="24" fill="rgba(255,255,255,.05)" stroke="rgba(255,244,204,.6)" stroke-width="3"/>
  <rect x="${tubX + 14}" y="${tubTop + 22}" width="8" height="${tubBot - tubTop - 60}" rx="4" fill="rgba(255,255,255,.18)"/>
  <rect x="${tubX - 8}" y="${bandY}" width="${tubW + 16}" height="10" rx="3" fill="${C.gold}"/>
  <g stroke="${C.kraft}" stroke-width="2" fill="${C.kraft}">
    <line x1="${tubX + tubW + 10}" y1="${bandY + 5}" x2="${tubX + tubW + 26}" y2="${bandY + 5}"/>
    <text class="hand" x="${tubX + tubW + 32}" y="${bandY + 11}" font-size="18" stroke="none">1×</text>
    <line x1="${tubX + tubW + 10}" y1="${x2}" x2="${tubX + tubW + 26}" y2="${x2}"/>
    <text class="hand" x="${tubX + tubW + 32}" y="${x2 + 6}" font-size="18" stroke="none">2×</text>
  </g>
  <text class="hand" x="${tubX + tubW / 2}" y="${tubBot + 38}" font-size="17" text-anchor="middle" fill="${C.kraft}">where it started: the gold band</text>

  <!-- the parts, falling in -->
${tiles}

  <!-- the proofing log -->
  <text class="hand" x="${rowX}" y="62" font-size="21" fill="${C.goldChalk}">${esc(d.eyebrow)}</text>
  <text class="display" x="${rowX}" y="108" font-size="42" fill="${C.flour}">${esc(d.title)}</text>
  <text class="body" x="${rowX}" y="142" font-size="16" fill="${C.kraft}">${esc(d.lede)}</text>
${rows}

  <!-- the feeding schedule, pinned along the bottom -->
  <rect x="40" y="414" width="1200" height="50" rx="12" fill="${C.slate}" stroke="${C.wood}" stroke-width="3"/>
  <text class="hand" x="66" y="446" font-size="20" fill="${C.goldChalk}">${esc(d.feed.when)}</text>
  <text class="mono" x="262" y="445" font-size="15" fill="${C.chalk}"><tspan fill="${C.chalkDim}">$ </tspan>${esc(d.feed.cmd)}</text>
  <text class="body" x="530" y="445" font-size="15" fill="${C.chalkDim}">${esc(d.feed.text)}</text>
</svg>
`;
}

async function buildProof(page) {
  console.log("proof:");
  const recipe = await page.$$eval(".recipe tbody tr", (trs) =>
    trs.map((tr) => ({
      part: tr.querySelector(".part").textContent.trim(),
      text: tr.children[2].textContent.trim(),
    })),
  );
  const rows = await page.$$eval(".log__entry", (els) =>
    els.map((el) => ({
      time: el.querySelector(".log__time").textContent.trim(),
      title: el.querySelector("strong").textContent.trim(),
      text: [...el.querySelector(".log__what").childNodes]
        .filter((n) => n.nodeName !== "STRONG")
        .map((n) => n.textContent)
        .join("")
        .replace(/\s+/g, " ")
        .trim(),
    })),
  );
  if (rows.length !== 7) fail(`expected seven proofing-log entries, found ${rows.length}`);
  // A log line that names an internal platform falls back to the recipe-card
  // row for the same part, which says the same thing without it.
  for (const r of rows) {
    if (!FORBIDDEN.test(r.text)) continue;
    const part = r.title.split(":")[1]?.replace(".", "").trim();
    const hit = recipe.find((x) => x.part.toLowerCase() === (part ?? "").toLowerCase());
    if (!hit) fail(`no clean fallback for log line "${r.title}"`);
    r.text = hit.text;
  }
  const lede = (await page.locator(".proof__lede").innerText()).trim();
  const eyebrow = (await page.locator(".proof__head .eyebrow").innerText()).trim();
  const title = (await page.locator("#proof-title").innerText()).trim();
  // The feeding schedule's "every day" row: the caption under the tub.
  const feed = await page.$$eval(".schedule__row", (els) => {
    const el = els[1];
    return {
      when: el.querySelector(".schedule__when").textContent.trim(),
      cmd: el.querySelector(".chalk-cmd").textContent.trim(),
      text: el.querySelector(".schedule__what > p").textContent.trim(),
    };
  });
  const data = { rows, lede, eyebrow, title, feed };
  checkClean(JSON.stringify(data), "proof data");
  record("proof.svg", Buffer.from(buildProofSvg(data), "utf8"));
}

/* -------------------------------------------------------------- 6. gallery */

// A contact sheet of two cuts of the real page, composed as a tiny HTML page
// and screenshotted: the five loaves on the bench, over the feeding schedule.
async function buildGallery(page) {
  console.log("gallery:");
  // The bench: the loaves and their labels, cut tight from the page.
  const box = await page.evaluate(() => {
    const parts = [...document.querySelectorAll(".proof__stage .loaves, .proof__stage .bench")].map((el) => el.getBoundingClientRect());
    const x1 = Math.min(...parts.map((r) => r.left));
    const y1 = Math.min(...parts.map((r) => r.top));
    const x2 = Math.max(...parts.map((r) => r.right));
    const y2 = Math.max(...parts.map((r) => r.bottom));
    return { x: x1 + window.scrollX, y: y1 + window.scrollY, width: x2 - x1, height: y2 - y1 };
  });
  checkClean(await page.locator(".proof__stage").innerText(), "gallery bench");
  const pad = 28;
  const bench = await page.screenshot({
    fullPage: true,
    clip: { x: box.x - pad, y: box.y - pad, width: box.width + 2 * pad, height: box.height + 2 * pad },
  });
  const board = await shot(page, ".chalkboard", "gallery chalkboard");
  const uri = (b) => `data:image/png;base64,${b.toString("base64")}`;
  const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<style>
  html, body { margin: 0; padding: 0; }
  .sheet { width: 1280px; box-sizing: border-box; background: linear-gradient(180deg, ${C.green}, ${C.greenDeep}); padding: 36px; display: flex; flex-direction: column; gap: 28px; }
  .sheet > div { border-radius: 14px; overflow: hidden; border: 1px solid rgba(255, 204, 51, .4); }
  .sheet img { display: block; width: 100%; }
</style></head>
<body><div class="sheet">
  <div><img src="${uri(bench)}" alt=""></div>
  <div><img style="margin-bottom:-24px" src="${uri(board)}" alt=""></div>
</div></body></html>`;
  await page.setContent(html, { waitUntil: "load" });
  const png = await page.locator(".sheet").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 80, mozjpeg: true }).toBuffer();
  record("gallery.jpg", jpg);
}

/* ---------------------------------------------------------------- 7. board */

async function buildBoard() {
  console.log("board:");
  const src = path.join(siteDir, "brand", "sourdough-board.png");
  const jpg = await sharp(readFileSync(src)).resize({ width: 1600, withoutEnlargement: true }).jpeg({ quality: 78, mozjpeg: true }).toBuffer();
  record("board.jpg", jpg);
}

/* -------------------------------------------------------------------- main */

async function main() {
  await buildCast();
  buildBadges();
  await buildBoard();

  const browser = await chromium.launch();
  try {
    const first = await openPage(browser);
    await buildProof(first.page);
    await buildGallery(first.page); // replaces the document, so the hero reopens it
    await first.context.close();
    const again = await openPage(browser);
    await buildHero(again.page);
    await again.context.close();
  } finally {
    await browser.close();
  }

  // The size gate: print what we have, fail if anything grew past the limit.
  let over = false;
  for (const { rel, bytes } of outputs) {
    if (bytes > MAX_BYTES) {
      console.error(`FAIL: ${rel} is ${bytes} bytes (limit ${MAX_BYTES})`);
      over = true;
    }
  }
  if (over) process.exit(1);
  console.log(`done: ${outputs.length} files, all at or under ${MAX_BYTES} bytes`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
