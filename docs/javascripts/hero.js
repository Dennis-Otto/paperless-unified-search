// SPDX-FileCopyrightText: 2026 Dennis Otto
// SPDX-License-Identifier: AGPL-3.0-or-later

// The animations of the website: the search in the hero of the home page, docs/index.md
// and docs/index.de.md, and the set-up on the page Configure, docs/configure.md and
// docs/configure.de.md. Each video, light and dark, shows its first frame,
// NAME-poster.webp beside NAME.mp4, until it plays, and in its place for a reader who
// asks for less motion. A browser loads the poster of every video, also of one that
// doesn't show, so each video gets its poster only once it comes near the screen, which
// the video of the other scheme, and both of the hero on a phone, never do; until the
// reader switches the scheme or turns the phone.
(() => {
  const videos = document.querySelectorAll(
    ".pus-hero__media video, .pus-animation video",
  );
  if (videos.length === 0 || !("IntersectionObserver" in window)) {
    return;
  }
  const observer = new IntersectionObserver(
    (entries) => {
      for (const { target: video, isIntersecting } of entries) {
        if (isIntersecting) {
          video.poster = video.getAttribute("src").replace(/\.mp4$/, "-poster.webp");
          observer.unobserve(video);
        }
      }
    },
    { rootMargin: "200px" },
  );
  for (const video of videos) {
    observer.observe(video);
  }
})();
