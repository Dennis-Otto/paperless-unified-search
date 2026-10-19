// The script of every website, which the blueprint keeps current: the hook in
// .github/mkdocs_blueprint.py serves it as assets/javascripts/blueprint.js.
// https://github.com/Dennis-Otto/repo-blueprint

// A block of code that scrolls sideways takes the focus, so that a reader scrolls it
// with the keyboard (WCAG 2.1.1). Material does that only once the block comes into
// view, so the keyboard would skip the blocks further down.
window.addEventListener("load", () => {
  for (const code of document.querySelectorAll(".md-typeset pre > code")) {
    if (code.scrollWidth > code.clientWidth && !code.hasAttribute("tabindex")) {
      code.setAttribute("tabindex", "0");
    }
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
