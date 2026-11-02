// The script of every website, which the blueprint keeps current: the hook in
// .github/mkdocs_blueprint.py serves it as assets/javascripts/blueprint.js.
// https://github.com/Dennis-Otto/repo-blueprint

// What scrolls sideways takes the focus, so that a reader scrolls it with the keyboard
// (WCAG 2.1.1): a block of code, and the frame that Material puts around a table, which
// scrolls on a phone. Material does that for a block only once it comes into view, so
// the keyboard would skip those further down, and never for a table. Whether it scrolls
// can change after the load, as a phone turns or a fold opens, and each of them calls
// the observer then; a frame that fits again keeps the focus, which does no harm.
window.addEventListener("load", () => {
  const observer = new ResizeObserver((entries) => {
    for (const { target } of entries) {
      if (target.scrollWidth > target.clientWidth && !target.hasAttribute("tabindex")) {
        target.setAttribute("tabindex", "0");
      }
    }
  });
  for (const each of document.querySelectorAll(".md-typeset pre > code, .md-typeset__scrollwrap")) {
    observer.observe(each);
  }
});

// A video that the website shows in place of a GIF plays, muted and in a loop as the
// GIF, while at least half of it is in view, and pauses out of view, so that it loads
// only once a reader scrolls to it. It stays still for a reader whose system asks for
// less motion, and for one who paused it, who start it with its controls.
(() => {
  const still = window.matchMedia("(prefers-reduced-motion: reduce)");
  const videos = document.querySelectorAll("video.blueprint-video");
  if (videos.length === 0 || !("IntersectionObserver" in window)) {
    return;
  }
  const pausedByUs = new WeakSet();
  const pausedByReader = new WeakSet();
  const observer = new IntersectionObserver(
    (entries) => {
      for (const { target: video, isIntersecting } of entries) {
        if (isIntersecting) {
          if (!still.matches && !pausedByReader.has(video)) {
            video.play().catch(() => {});
          }
        } else if (!video.paused) {
          pausedByUs.add(video);
          video.pause();
        }
      }
    },
    { threshold: 0.5 },
  );
  for (const video of videos) {
    video.addEventListener("pause", () => {
      if (!pausedByUs.delete(video)) {
        pausedByReader.add(video);
      }
    });
    video.addEventListener("play", () => pausedByReader.delete(video));
    observer.observe(video);
  }
})();
