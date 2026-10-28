/* Sourdough front page. Small, vanilla, no build.
   With reduced motion the page is already complete in the HTML: every loaf
   out, every log entry written, every crumb open. The script only adds the
   rise, the scoring and the feeding. */
(function () {
  "use strict";

  var root = document.documentElement;
  var motion = root.classList.contains("motion");
  var clamp = function (v, a, b) { return Math.min(b, Math.max(a, v)); };
  var ease = function (t) { return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; };
  var easeOut = function (t) { return 1 - Math.pow(1 - t, 3); };

  /* ----------------------------------------------------------- the toast */
  var toast = document.querySelector(".toast");
  var toastTimer;
  function say(text) {
    if (!toast) return;
    toast.textContent = text;
    toast.classList.add("is-up");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toast.classList.remove("is-up");
      setTimeout(function () { if (!toast.classList.contains("is-up")) toast.textContent = ""; }, 500);
    }, 2800);
  }

  /* -------------------------------------------------------------- copy */
  document.querySelectorAll("[data-copy]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var text = btn.getAttribute("data-copy");
      var done = function () {
        var old = btn.textContent;
        btn.textContent = "Copied";
        say("Copied. Fresh out of the oven.");
        setTimeout(function () { btn.textContent = old; }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { say("Copy didn't take. Select the text and copy it by hand."); });
      } else {
        say("Select the text and copy it by hand.");
      }
    });
  });

  /* -------------------------------------------------- feed the starter */
  var feedBtn = document.querySelector("[data-feed]");
  var feedCount = document.querySelector("[data-feed-count]");
  var jar = document.querySelector(".jar");
  var fed = 0;
  var lines = [
    "Fed once. Bubbling nicely.",
    "Fed twice. It's rising.",
    "Fed three times. It's getting a rise out of you.",
    "Fed four times. Well past the gold band.",
    "Fed five times. It's alive, and it's asking for rye."
  ];
  if (feedBtn && feedCount && jar) {
    feedBtn.addEventListener("click", function () {
      fed += 1;
      feedCount.textContent = fed <= lines.length ? lines[fed - 1] : "Fed " + fed + " times. That's a lot of loaves.";
      if (fed === 3) say("Careful: a well-fed starter makes a lot of apps.");
      if (fed === 10) say("Ten feedings. You could open a bakery.");
      if (!motion) return;
      jar.classList.remove("is-fed");
      void jar.offsetWidth; /* restart the bounce */
      jar.classList.add("is-fed");
      var bubbles = jar.querySelector(".jar__bubbles");
      for (var i = 0; i < 9; i += 1) {
        var b = document.createElement("span");
        b.className = "burst";
        b.style.left = (20 + Math.random() * 60) + "%";
        b.style.bottom = "0";
        b.style.setProperty("--bx", (Math.random() * 80 - 40) + "px");
        b.style.setProperty("--by", (-50 - Math.random() * 60) + "px");
        b.addEventListener("animationend", function () { this.remove(); });
        bubbles.appendChild(b);
      }
    });
  }

  /* ---------------------------------------------------------- the proof */
  var proof = document.querySelector("[data-proof]");
  if (proof && motion) {
    var svg = proof.querySelector(".stage");
    var tub = proof.querySelector("[data-tub]");
    var dough = proof.querySelector("[data-dough]");
    var bubbleLayer = proof.querySelector("[data-bubbles]");
    var pinch = proof.querySelector("[data-pinch]");
    var scraper = proof.querySelector("[data-scraper]");
    var turned = proof.querySelector("[data-turned]");
    var sloaves = Array.prototype.slice.call(proof.querySelectorAll("[data-sloaf]"));
    var entries = Array.prototype.slice.call(proof.querySelectorAll(".log__entry"));
    var pinches = ["mix", "+ flour: WayneUI", "+ water: SESAME", "+ salt: HELMET", "+ levain: PROCTOR", "doubled!", "divide"];
    var NS = "http://www.w3.org/2000/svg";
    var loafX = [64, 192, 320, 448, 576];

    svg.setAttribute("viewBox", "0 0 640 440");

    /* Bubbles: fixed positions, each appears once the rise passes it. A
       seeded sequence, so every visit bakes the same loaf. */
    var seed = 7;
    var rnd = function () { seed = (seed * 16807) % 2147483647; return (seed - 1) / 2147483646; };
    var bubbles = [];
    for (var i = 0; i < 26; i += 1) {
      var c = document.createElementNS(NS, "circle");
      c.setAttribute("class", "bubble");
      bubbleLayer.appendChild(c);
      bubbles.push({ el: c, x: 222 + rnd() * 196, fy: 0.08 + rnd() * 0.86, r: 2.5 + rnd() * 6.5, t: rnd() * 0.85 });
    }

    sloaves.forEach(function (g) {
      g.style.transformBox = "fill-box";
      g.style.transformOrigin = "50% 50%";
    });

    var lastStep = -1;
    var render = function (p) {
      /* 1. the rise */
      var rise = ease(clamp(p / 0.72, 0, 1));
      var top = 270 - rise * 122;
      var dome = 12 + rise * 22;
      dough.setAttribute("d", "M208 362 L208 " + (top + dome).toFixed(1) + " Q320 " + (top - dome).toFixed(1) + " 432 " + (top + dome).toFixed(1) + " L432 362 Z");
      var surface = top + dome;
      bubbles.forEach(function (b) {
        var on = rise > b.t;
        var grow = on ? clamp((rise - b.t) * 6, 0, 1) : 0;
        b.el.setAttribute("cx", b.x.toFixed(1));
        b.el.setAttribute("cy", (362 - (362 - surface) * b.fy).toFixed(1));
        b.el.setAttribute("r", (b.r * (0.35 + 0.65 * grow)).toFixed(2));
        b.el.style.opacity = grow.toFixed(2);
      });

      /* 2. divide: turn it out, cut it, shape five loaves */
      var d = clamp((p - 0.78) / 0.22, 0, 1);
      tub.style.opacity = (1 - clamp(d / 0.25, 0, 1)).toFixed(3);
      turned.style.opacity = (clamp(d / 0.2, 0, 1) * (1 - clamp((d - 0.42) / 0.12, 0, 1))).toFixed(3);
      var cut = clamp((d - 0.18) / 0.3, 0, 1);
      scraper.style.opacity = (Math.sin(cut * Math.PI)).toFixed(3);
      scraper.style.transform = "translate(" + (150 + cut * 340).toFixed(1) + "px, 40px)";
      var le = easeOut(clamp((d - 0.4) / 0.42, 0, 1));
      sloaves.forEach(function (g, n) {
        var dx = (320 - loafX[n]) * 0.55 * (1 - le);
        var dy = 12 * (1 - le);
        g.style.transform = "translate(" + dx.toFixed(1) + "px," + dy.toFixed(1) + "px) scale(" + (0.5 + 0.5 * le).toFixed(3) + ")";
        g.style.opacity = clamp((d - 0.4) / 0.12, 0, 1).toFixed(3);
        var s = g.querySelector(".score");
        if (s) s.style.strokeDashoffset = (1 - clamp((d - 0.8) / 0.15, 0, 1)).toFixed(3);
        var t = g.querySelector("text");
        if (t) t.style.opacity = clamp((d - 0.85) / 0.12, 0, 1).toFixed(3);
      });

      /* 3. the log */
      var step = 0;
      entries.forEach(function (li, n) { if (p >= parseFloat(li.getAttribute("data-at"))) step = n; });
      entries.forEach(function (li, n) {
        li.classList.toggle("is-done", n < step);
        li.classList.toggle("is-now", n === step);
      });
      if (step !== lastStep) {
        lastStep = step;
        pinch.textContent = pinches[step] || "";
        pinch.classList.remove("is-dropping");
        void pinch.getBBox;
        requestAnimationFrame(function () { pinch.classList.add("is-dropping"); });
      }
    };

    var ticking = false;
    var progress = function () {
      var r = proof.getBoundingClientRect();
      var span = r.height - window.innerHeight;
      return span > 0 ? clamp(-r.top / span, 0, 1) : 1;
    };
    var onScroll = function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () { ticking = false; render(progress()); });
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    window.addEventListener("resize", onScroll);
    render(progress());
  }

  /* ------------------------------------------------------ score a loaf */
  document.querySelectorAll("[data-score]").forEach(function (btn) {
    var loaf = btn.closest(".loaf");
    var baked = loaf.classList.contains("loaf--baked");
    var hint = btn.querySelector(".loaf__hint");
    var setOpen = function (open) {
      btn.setAttribute("aria-expanded", open ? "true" : "false");
      loaf.classList.toggle("is-closed", !open);
      loaf.classList.toggle("is-scored", open);
      if (hint) hint.textContent = open ? "Scored" : "Score it";
    };
    /* With motion the crumb waits for the blade; without it, it is open. */
    setOpen(!motion);
    btn.addEventListener("click", function () {
      var open = btn.getAttribute("aria-expanded") !== "true";
      setOpen(open);
      if (open) say(baked ? "Open crumb, crisp crust. That one's done." : "Still dough in the middle. Coming soon.");
    });
  });

  /* -------------------------------------------------------------- tabs */
  document.querySelectorAll("[data-tabs]").forEach(function (box) {
    var tabs = Array.prototype.slice.call(box.querySelectorAll('[role="tab"]'));
    var pick = function (tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute("aria-controls")).hidden = !on;
      });
      if (focus) tab.focus();
    };
    tabs.forEach(function (tab, n) {
      tab.addEventListener("click", function () { pick(tab, false); });
      tab.addEventListener("keydown", function (e) {
        var to = null;
        if (e.key === "ArrowRight") to = tabs[(n + 1) % tabs.length];
        if (e.key === "ArrowLeft") to = tabs[(n - 1 + tabs.length) % tabs.length];
        if (e.key === "Home") to = tabs[0];
        if (e.key === "End") to = tabs[tabs.length - 1];
        if (to) { e.preventDefault(); pick(to, true); }
      });
    });
    /* Start on the visitor's own system when we can tell. */
    var ua = (navigator.userAgent || "").toLowerCase();
    var guess = ua.indexOf("mac") > -1 ? 1 : (ua.indexOf("linux") > -1 && ua.indexOf("android") === -1 ? 2 : 0);
    if (tabs[guess]) pick(tabs[guess], false);
  });

  /* ------------------------------------------------- chalk reveals */
  var reveals = document.querySelectorAll("[data-reveal]");
  if (motion && "IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (list) {
      list.forEach(function (en) {
        if (en.isIntersecting) { en.target.classList.add("is-in"); io.unobserve(en.target); }
      });
    }, { threshold: 0.2 });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add("is-in"); });
  }
})();
