'use strict';
/* HEISVERSE admin: small helpers only. All security checks happen on the server. */

// Ask before destructive actions: any button with data-confirm="message".
document.addEventListener('click', e => {
  const b = e.target.closest('[data-confirm]');
  if (b && !confirm(b.dataset.confirm)) e.preventDefault();
});

// Sources page: show the right hint (and the upload box) for the chosen source type.
const type = document.getElementById('srcType');
if (type) {
  const hint = document.getElementById('srcHint'), ref = document.getElementById('srcRef'), box = document.getElementById('srcFileBox');
  const help = {
    internet_archive: ['Internet Archive identifier or archive.org/details link.', 'night_of_the_living_dead'],
    youtube: ['Link or 11-character id of a video on an official or authorized channel.', 'https://www.youtube.com/watch?v=...'],
    official_page: ['Full https:// link to the official page where the film is free to watch.', 'https://...'],
    direct_file: ['https:// link to an .mp4, .webm or .ogv file, or upload one below.', 'https://.../film.mp4']
  };
  const sync = () => {
    const h = help[type.value] || help.internet_archive;
    hint.textContent = h[0]; ref.placeholder = h[1]; box.hidden = type.value !== 'direct_file';
  };
  type.addEventListener('change', sync); sync();
}
