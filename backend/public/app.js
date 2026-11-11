document.querySelectorAll('[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});

document.querySelectorAll('[data-copy]').forEach(button => {
  button.addEventListener('click', async () => {
    const field = document.getElementById(button.dataset.copy);
    const status = document.querySelector('.copy-status');
    try {
      await navigator.clipboard.writeText(field.value);
      status.textContent = 'Upload link copied.';
    } catch {
      field.focus();
      field.select();
      status.textContent = 'Select and copy the link above.';
    }
  });
});

const photoInput = document.getElementById('photos');
if (photoInput) {
  const selection = document.querySelector('.upload-selection');
  const previews = selection.querySelector('.upload-previews');
  const summary = document.getElementById('file-info');
  const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
  const decodeErrors = new Set();
  let previewUrls = [];

  const formatSize = bytes => bytes >= 1024 * 1024
    ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;

  function fileError(file) {
    if (file.size > 10 * 1024 * 1024) return 'This photo exceeds the 10 MB limit.';
    if (!allowedTypes.has(file.type) && (file.type || !/\.(jpe?g|png|webp)$/i.test(file.name))) {
      return 'Choose a JPEG, PNG or WebP image.';
    }
    return '';
  }

  function releasePreviews() {
    previewUrls.forEach(url => URL.revokeObjectURL(url));
    previewUrls = [];
  }

  function updateSummary() {
    const files = Array.from(photoInput.files);
    const error = files.length > 10 ? 'Choose no more than 10 photos. Remove some photos below.'
      : files.map(fileError).find(Boolean) || (decodeErrors.size ? 'Remove photos that could not be previewed, or choose different files.' : '');
    photoInput.setCustomValidity(error);
    photoInput.setAttribute('aria-invalid', String(Boolean(error)));
    summary.classList.toggle('selection-error', Boolean(error));
    const count = files.length;
    const total = files.reduce((bytes, file) => bytes + file.size, 0);
    summary.textContent = error || (count ? `${count} photo${count === 1 ? '' : 's'} selected · ${formatSize(total)} total. Ready to upload.` : 'No photos selected.');
  }

  function removePhoto(index) {
    const files = Array.from(photoInput.files);
    try {
      const remaining = new DataTransfer();
      files.forEach((file, fileIndex) => {
        if (fileIndex !== index) remaining.items.add(file);
      });
      photoInput.files = remaining.files;
    } catch {
      summary.textContent = 'Your browser cannot remove individual photos. Choose files again to replace your selection.';
      photoInput.focus();
      return;
    }
    renderPreviews();
    const buttons = previews.querySelectorAll('button');
    (buttons[Math.min(index, buttons.length - 1)] || photoInput).focus();
  }

  function renderPreviews() {
    releasePreviews();
    decodeErrors.clear();
    previews.replaceChildren();
    const files = Array.from(photoInput.files);
    selection.hidden = files.length === 0;
    files.forEach((file, index) => {
      const card = document.createElement('li');
      card.className = 'upload-preview';
      const media = document.createElement('div');
      media.className = 'preview-media';
      const details = document.createElement('div');
      details.className = 'preview-details';
      const name = document.createElement('strong');
      name.className = 'preview-name';
      name.textContent = file.name;
      const size = document.createElement('span');
      size.className = 'preview-size';
      size.textContent = formatSize(file.size);
      const message = document.createElement('span');
      message.className = 'preview-error';
      const error = fileError(file);
      message.textContent = error;
      message.hidden = !error;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'preview-remove';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', `Remove photo ${file.name}`);
      remove.addEventListener('click', () => removePhoto(index));
      details.append(name, size, message, remove);
      card.append(media, details);
      previews.append(card);
      if (error) {
        media.textContent = 'Preview unavailable';
        card.classList.add('has-error');
      } else {
        const image = document.createElement('img');
        image.alt = `Preview of ${file.name}`;
        image.decoding = 'async';
        image.addEventListener('error', () => {
          if (!card.isConnected) return;
          decodeErrors.add(index);
          card.classList.add('has-error');
          media.textContent = 'Preview unavailable';
          message.textContent = 'This image could not be previewed. Remove it or choose a different file.';
          message.hidden = false;
          updateSummary();
        });
        const url = URL.createObjectURL(file);
        previewUrls.push(url);
        image.src = url;
        media.append(image);
      }
    });
    updateSummary();
  }

  photoInput.addEventListener('change', renderPreviews);
  window.addEventListener('pagehide', releasePreviews);
  window.addEventListener('pageshow', event => {
    if (event.persisted) renderPreviews();
  });
}

document.querySelectorAll('form[data-upload]').forEach(form => {
  form.addEventListener('submit', () => {
    const button = form.querySelector('[type=submit]');
    button.disabled = true;
    button.textContent = 'Uploading…';
    form.querySelectorAll('.preview-remove').forEach(remove => { remove.disabled = true; });
    form.querySelector('.upload-status').textContent = 'Sending your photos. Please keep this page open.';
  });
});
