/*
 * Teaser of the home page (YouTube): a local thumbnail links to the video, and YouTube is
 * loaded only when the visitor clicks it (no request to YouTube before). The player is the
 * youtube-nocookie one; without JavaScript, the link opens the video on YouTube.
 */
document.addEventListener('click', function (event) {
  var link = event.target.closest && event.target.closest('a.teaser[data-youtube]');
  if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
    return;
  }
  event.preventDefault();
  var player = document.createElement('iframe');
  player.className = 'teaser';
  player.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(link.dataset.youtube) + '?autoplay=1&rel=0';
  player.title = link.getAttribute('title') || 'YouTube';
  player.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
  player.referrerPolicy = 'strict-origin-when-cross-origin';
  player.allowFullscreen = true;
  link.replaceWith(player);
  player.focus();
});
