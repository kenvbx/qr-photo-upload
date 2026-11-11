document.addEventListener('submit', event => {
  const form = event.target.closest('[data-confirm]');
  if (form && !window.confirm(form.dataset.confirm)) event.preventDefault();
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
  const form = photoInput.closest('form');
  const selection = form.querySelector('.upload-selection');
  const previews = selection.querySelector('.upload-previews');
  const summary = document.getElementById('file-info');
  const status = form.querySelector('.upload-status');
  const submit = form.querySelector('[type=submit]');
  const retryFailed = form.querySelector('.retry-failed');
  const totalPanel = form.querySelector('.upload-total');
  const totalProgress = form.querySelector('#upload-progress');
  const totalLabel = form.querySelector('.upload-total-label');
  const allowedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
  const compression = form.querySelector('[data-compress]');
  const enhanced = typeof XMLHttpRequest !== 'undefined' && Boolean(globalThis.crypto?.getRandomValues);
  let items = [];
  let previewUrls = [];
  let busy = false;
  let cooldownTimer;

  const formatSize = bytes => bytes >= 1024 * 1024
    ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    : `${Math.max(1, Math.ceil(bytes / 1024))} KB`;
  const confirmed = item => item.state === 'uploaded' || item.state === 'duplicate';
  const canRetry = item => item.state === 'failed' && item.retryable && item.retryAt <= Date.now();

  function uploadId() {
    if (!enhanced) return null;
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

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
    const error = items.length > 10 ? 'Choose no more than 10 photos. Remove some photos below.'
      : items.map(item => fileError(item.file) || item.previewError).find(Boolean) || '';
    photoInput.setCustomValidity(error);
    photoInput.setAttribute('aria-invalid', String(Boolean(error)));
    summary.classList.toggle('selection-error', Boolean(error));
    const count = items.length;
    const total = items.reduce((bytes, item) => bytes + item.file.size, 0);
    const ready = items.every(item => item.state === 'ready');
    summary.textContent = error || (count ? `${count} photo${count === 1 ? '' : 's'} selected · ${formatSize(total)} total.${ready ? ' Ready to upload.' : ''}` : 'No photos selected.');
  }

  function updateControls() {
    photoInput.disabled = busy;
    if (compression) compression.disabled = busy || items.some(item => item.uploadFile);
    selection.querySelector('.preview-heading .muted').textContent = busy ? 'Keep this page open' : (items.some(item => item.state !== 'ready') ? 'Uploads tracked below' : 'Not uploaded yet');
    selection.querySelector('.selection-help').textContent = busy ? 'Your selection is locked while uploads are running.'
      : (items.length && items.every(confirmed) ? 'All photos confirmed. Choose more files above to start a new selection.' : "Remove any photos you don't want to share. Choosing files again replaces this selection.");
    submit.disabled = busy || (items.length > 0 && !items.some(item => item.state === 'ready'));
    submit.textContent = busy ? 'Uploading…' : (items.length > 0 && items.every(confirmed) ? 'Uploaded' : 'Upload photos ↑');
    retryFailed.hidden = !items.some(item => item.state === 'failed' && item.retryable);
    retryFailed.disabled = busy || !items.some(canRetry);
    clearTimeout(cooldownTimer);
    const waiting = items.filter(item => item.state === 'failed' && item.retryable && item.retryAt > Date.now());
    if (waiting.length) cooldownTimer = setTimeout(updateControls, Math.min(...waiting.map(item => item.retryAt - Date.now())) + 50);
    items.forEach(item => {
      if (!item.card) return;
      item.remove.disabled = busy;
      item.remove.hidden = confirmed(item);
      item.retry.hidden = item.state !== 'failed' || !item.retryable;
      item.retry.disabled = busy || !canRetry(item);
    });
  }

  function updateProgress(item) {
    item.card.dataset.state = item.state;
    item.progressBar.hidden = item.state === 'ready';
    item.progressBar.value = item.percent;
    item.progressText.textContent = item.message;
    item.progressText.classList.toggle('selection-error', item.state === 'failed');
    totalPanel.hidden = !items.some(entry => entry.state !== 'ready');
    const bytes = items.reduce((sum, entry) => sum + entry.file.size, 0);
    const sent = items.reduce((sum, entry) => sum + entry.file.size * entry.percent / 100, 0);
    const done = items.filter(confirmed).length;
    totalProgress.value = done === items.length ? 100 : Math.min(99, bytes ? Math.round(sent / bytes * 100) : 0);
    totalLabel.textContent = `${done} of ${items.length} photos confirmed · ${totalProgress.value}% overall`;
    updateControls();
  }

  function removePhoto(item) {
    if (busy || confirmed(item)) return;
    const index = items.indexOf(item);
    const remainingItems = items.filter(entry => entry !== item);
    try {
      const remaining = new DataTransfer();
      remainingItems.forEach(entry => remaining.items.add(entry.file));
      photoInput.files = remaining.files;
    } catch {
      summary.textContent = 'Your browser cannot remove individual photos. Choose files again to replace your selection.';
      photoInput.focus();
      return;
    }
    items = remainingItems;
    renderPreviews();
    const buttons = [...previews.querySelectorAll('.preview-remove')].filter(button => !button.hidden);
    (buttons[Math.min(index, buttons.length - 1)] || photoInput).focus();
  }

  function renderPreviews() {
    releasePreviews();
    previews.replaceChildren();
    selection.hidden = items.length === 0;
    items.forEach(item => {
      const card = document.createElement('li');
      card.className = 'upload-preview';
      const media = document.createElement('div');
      media.className = 'preview-media';
      const details = document.createElement('div');
      details.className = 'preview-details';
      const name = document.createElement('strong');
      name.className = 'preview-name';
      name.textContent = item.file.name;
      const size = document.createElement('span');
      size.className = 'preview-size';
      size.textContent = formatSize(item.file.size);
      const message = document.createElement('span');
      message.className = 'preview-error';
      const error = fileError(item.file) || item.previewError;
      message.textContent = error || '';
      message.hidden = !error;
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'preview-remove';
      remove.textContent = 'Remove';
      remove.setAttribute('aria-label', `Remove photo ${item.file.name}`);
      remove.addEventListener('click', () => removePhoto(item));
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'preview-retry secondary';
      retry.textContent = 'Retry';
      retry.setAttribute('aria-label', `Retry photo ${item.file.name}`);
      retry.hidden = true;
      retry.addEventListener('click', () => runUploads([item]));
      const progressBar = document.createElement('progress');
      progressBar.max = 100;
      progressBar.value = item.percent;
      progressBar.setAttribute('aria-label', `Upload progress for ${item.file.name}`);
      progressBar.hidden = item.state === 'ready';
      const progressText = document.createElement('span');
      progressText.className = 'photo-upload-state';
      progressText.textContent = item.message;
      const actions = document.createElement('div');
      actions.className = 'preview-actions';
      actions.append(remove, retry);
      details.append(name, size, message, progressBar, progressText, actions);
      card.append(media, details);
      previews.append(card);
      Object.assign(item, { card, remove, retry, progressBar, progressText });
      if (error) {
        media.textContent = 'Preview unavailable';
        card.classList.add('has-error');
      } else {
        const image = document.createElement('img');
        image.alt = `Preview of ${item.file.name}`;
        image.decoding = 'async';
        image.addEventListener('error', () => {
          if (!card.isConnected) return;
          item.previewError = 'This image could not be previewed. Remove it or choose a different file.';
          card.classList.add('has-error');
          media.textContent = 'Preview unavailable';
          message.textContent = item.previewError;
          message.hidden = false;
          updateSummary();
        });
        const url = URL.createObjectURL(item.file);
        previewUrls.push(url);
        image.src = url;
        media.append(image);
      }
      updateProgress(item);
    });
    if (!items.length) totalPanel.hidden = true;
    updateSummary();
    updateControls();
  }

  async function preparePhoto(item) {
    if (item.uploadFile) return;
    item.uploadFile = item.file;
    if (!compression?.checked || typeof createImageBitmap !== 'function') return;
    let bitmap;
    try {
      bitmap = await createImageBitmap(item.file);
      if (bitmap.width > 12000 || bitmap.height > 12000 || bitmap.width * bitmap.height > 20000000) return;
      const scale = Math.min(1, 2048 / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(bitmap.width * scale));
      canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      const context = canvas.getContext('2d');
      context.fillStyle = '#fff'; context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.82));
      if (blob && blob.size < item.file.size) {
        item.uploadFile = new File([blob], item.file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
        item.savings = ` · reduced ${Math.round((1 - blob.size / item.file.size) * 100)}%`;
      }
    } catch { /* The original remains available if compression is unsupported. */ }
    finally { bitmap?.close(); }
  }

  function sendPhoto(item) {
    return new Promise(resolve => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', form.action);
      xhr.responseType = 'json';
      xhr.timeout = 180000;
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-CSRF-TOKEN', form.querySelector('[name=_token]').value);
      xhr.upload.addEventListener('progress', event => {
        if (!event.lengthComputable) return;
        item.percent = Math.min(100, Math.round(event.loaded / event.total * 100));
        item.message = item.percent === 100 ? 'Sent · confirming with server…' : `Uploading · ${item.percent}%`;
        updateProgress(item);
      });
      xhr.upload.addEventListener('load', () => {
        item.percent = 100;
        item.message = 'Sent · confirming with server…';
        updateProgress(item);
      });
      const uncertain = () => resolve({ ok: false, retryable: true, message: 'Could not confirm upload. Retry safely; an accepted photo will not be saved twice.' });
      xhr.addEventListener('error', uncertain);
      xhr.addEventListener('timeout', uncertain);
      xhr.addEventListener('abort', uncertain);
      xhr.addEventListener('load', () => {
        const result = xhr.response?.data;
        if (xhr.status >= 200 && xhr.status < 300) {
          if (result && Number.isInteger(result.uploaded) && Number.isInteger(result.duplicates) && result.uploaded >= 0 && result.duplicates >= 0 && result.uploaded + result.duplicates === 1 && typeof result.replayed === 'boolean') {
            resolve({ ok: true, result });
          } else uncertain();
          return;
        }
        const messages = {
          404: 'This upload link is no longer available.',
          409: 'This upload ID conflicts with another file. Choose the photo again.',
          410: 'This upload link has expired or closed.',
          413: 'This photo exceeds the server upload limit. Choose a smaller photo.',
          419: 'Your session expired. Reload this page and choose the photos again.',
          401: 'Your session expired. Reload this page before uploading.',
          403: 'This upload was not allowed. Reload this page before uploading.'
        };
        const retryable = xhr.status === 429 || xhr.status >= 500;
        const retrySeconds = Math.min(300, Math.max(1, Number(xhr.getResponseHeader('Retry-After')) || 60));
        const validation = xhr.response?.errors ? Object.values(xhr.response.errors).flat().find(value => typeof value === 'string') : null;
        resolve({ ok: false, retryable, automatic: xhr.status >= 500, retryAt: xhr.status === 429 ? Date.now() + retrySeconds * 1000 : 0,
          stop: [401, 403, 404, 410, 419].includes(xhr.status),
          message: messages[xhr.status] || (xhr.status === 429 ? `Too many uploads. Retry after ${retrySeconds} second${retrySeconds === 1 ? '' : 's'}.` : (xhr.status === 422 ? validation || 'This photo was rejected. Choose a different photo.' : (retryable ? 'Server could not confirm upload. Please retry.' : 'Upload was rejected. Reload this page or choose the photos again.'))) });
      });
      const body = new FormData();
      body.append('upload_key', item.key);
      body.append('photos[]', item.uploadFile || item.file, (item.uploadFile || item.file).name);
      try { xhr.send(body); } catch { uncertain(); }
    });
  }

  async function runUploads(targets) {
    if (busy) return;
    const queue = targets.filter(item => item.state === 'ready' || canRetry(item));
    if (!queue.length) return;
    busy = true;
    status.textContent = 'Uploading photos. Keep this page open until the server confirms them.';
    updateControls();
    try {
      for (let index = 0; index < queue.length; index++) {
        const item = queue[index];
        await preparePhoto(item);
        item.state = 'uploading';
        item.retryAt = 0;
        let response;
        for (let attempt = 0; attempt < 2; attempt++) {
          item.percent = 0;
          item.message = attempt ? 'Connection interrupted · retrying safely…' : 'Uploading · 0%';
          updateProgress(item);
          if (attempt) await new Promise(resolve => setTimeout(resolve, 750));
          response = await sendPhoto(item);
          if (response.ok || !response.retryable || response.retryAt || response.automatic === false || attempt === 1) break;
        }
        if (response.ok) {
          item.state = response.result.duplicates ? 'duplicate' : 'uploaded';
          item.percent = 100;
          item.message = response.result.duplicates ? 'Already uploaded · no duplicate saved' : (response.result.replayed ? 'Upload confirmed · safely retried' : 'Uploaded');
        } else {
          item.state = 'failed';
          item.retryable = response.retryable;
          item.retryAt = response.retryAt || 0;
          item.message = response.message;
        }
        if (response.ok && item.savings) item.message += item.savings;
        updateProgress(item);
        if (response.stop) {
          queue.slice(index + 1).forEach(remaining => {
            remaining.state = 'failed'; remaining.retryable = false; remaining.message = response.message;
            updateProgress(remaining);
          });
          break;
        }
      }
    } finally {
      busy = false;
      const done = items.filter(confirmed).length;
      const failed = items.filter(item => item.state === 'failed').length;
      status.textContent = failed ? `${done} of ${items.length} photos confirmed. ${failed} failed. Retry available photos below, or remove rejected photos.`
        : `Thank you! ${done} photo(s) confirmed. Photos already in this collection were not saved twice.`;
      updateSummary();
      updateControls();
    }
  }

  photoInput.addEventListener('change', () => {
    if (busy) return;
    items = Array.from(photoInput.files).map(file => ({ file, key: uploadId(), state: 'ready', percent: 0, message: '', retryable: false, retryAt: 0, previewError: '' }));
    status.textContent = '';
    renderPreviews();
  });
  retryFailed.addEventListener('click', () => runUploads(items.filter(canRetry)));
  form.addEventListener('submit', event => {
    if (!enhanced) return;
    event.preventDefault();
    if (form.reportValidity()) runUploads(items.filter(item => item.state === 'ready'));
  });
  window.addEventListener('pagehide', releasePreviews);
  window.addEventListener('pageshow', event => {
    if (event.persisted) renderPreviews();
  });
}

function initializeGallery(gallery) {
  const form = gallery.querySelector('#photo-selection');
  const checks = [...gallery.querySelectorAll('input[name="photos[]"]')];
  const selectAll = gallery.querySelector('[data-select-all]');
  const clear = gallery.querySelector('[data-clear-selection]');
  const dialog = gallery.querySelector('.gallery-lightbox');
  const links = [...gallery.querySelectorAll('[data-lightbox-photo]')];
  let active = 0;
  let opener;

  function updateSelection() {
    const count = checks.filter(check => check.checked).length;
    gallery.querySelector('[data-selection-count]').textContent = `${count} selected`;
    selectAll.checked = count === checks.length;
    selectAll.indeterminate = count > 0 && count < checks.length;
    clear.hidden = count === 0;
    form.querySelectorAll('[data-bulk-action]').forEach(button => { button.disabled = count === 0; });
    checks.forEach(check => { check.closest('.photo-card').classList.toggle('is-selected', check.checked); });
    if (dialog) dialog.querySelector('[data-lightbox-select]').checked = checks[active].checked;
  }

  if (form) {
    selectAll.closest('label').hidden = false;
    checks.forEach(check => check.addEventListener('change', updateSelection));
    selectAll.addEventListener('change', () => {
      checks.forEach(check => { check.checked = selectAll.checked; });
      updateSelection();
    });
    clear.addEventListener('click', () => {
      checks.forEach(check => { check.checked = false; });
      updateSelection();
      selectAll.focus();
    });
    form.addEventListener('submit', event => {
      const count = checks.filter(check => check.checked).length;
      if (!count || (event.submitter?.value === 'delete' && !window.confirm(`Permanently delete ${count} selected photo${count === 1 ? '' : 's'}? This cannot be undone.`))) {
        event.preventDefault();
      }
    });
    // Selection listeners belong to this mounted gallery only.
    updateSelection();
  }

  if (dialog && typeof dialog.showModal === 'function') {
    const stage = dialog.querySelector('.lightbox-stage');
    const state = dialog.querySelector('.lightbox-state');
    let image = dialog.querySelector('.lightbox-image');
    const previous = dialog.querySelector('[data-lightbox-previous]');
    const next = dialog.querySelector('[data-lightbox-next]');
    const selected = dialog.querySelector('[data-lightbox-select]');

    function showPhoto(index) {
      active = index;
      const link = links[index];
      dialog.querySelector('#lightbox-title').textContent = link.dataset.title;
      dialog.querySelector('.lightbox-counter').textContent = `${index + 1} of ${links.length} on this page`;
      dialog.querySelector('.lightbox-details').textContent = link.dataset.details;
      dialog.querySelector('[data-lightbox-download]').href = link.dataset.download;
      selected.checked = checks[index].checked;
      previous.disabled = links.length < 2;
      next.disabled = links.length < 2;
      state.textContent = 'Loading photo…';
      stage.setAttribute('aria-busy', 'true');
      const freshImage = new Image();
      freshImage.className = 'lightbox-image';
      freshImage.alt = link.dataset.title;
      freshImage.hidden = true;
      freshImage.onload = () => {
        if (image !== freshImage) return;
        freshImage.hidden = false;
        state.textContent = '';
        stage.setAttribute('aria-busy', 'false');
      };
      freshImage.onerror = () => {
        if (image !== freshImage) return;
        state.textContent = 'This photo could not be loaded. Close and reopen to retry, or refresh the gallery.';
        stage.setAttribute('aria-busy', 'false');
      };
      image.replaceWith(freshImage);
      image = freshImage;
      freshImage.src = link.href;
    }
    const move = delta => showPhoto((active + delta + links.length) % links.length);
    links.forEach((link, index) => link.addEventListener('click', event => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      opener = link;
      showPhoto(index);
      dialog.showModal();
      document.body.classList.add('lightbox-open');
    }));
    previous.addEventListener('click', () => move(-1));
    next.addEventListener('click', () => move(1));
    selected.addEventListener('change', () => {
      checks[active].checked = selected.checked;
      updateSelection();
    });
    dialog.querySelector('[data-lightbox-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => {
      document.body.classList.remove('lightbox-open');
      opener?.focus();
    });
    dialog.addEventListener('keydown', event => {
      if (event.key === 'Tab') {
        const focusable = [...dialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled])')]
          .filter(element => element.getClientRects().length);
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        move(event.key === 'ArrowLeft' ? -1 : 1);
      }
    });
  }
}

const gallery = document.querySelector('[data-gallery]');
if (gallery) initializeGallery(gallery);

const liveContent = document.querySelector('[data-live-content]');
if (liveContent) {
  let revision;
  let timer;
  let stopped = false;
  async function refreshLiveContent() {
    if (stopped) return;
    if (!document.hidden && !liveContent.querySelector('dialog[open]')) {
      try {
        const response = await fetch(liveContent.dataset.liveUrl, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(15000) });
        if (!response.ok) throw new Error('Refresh unavailable');
        const data = await response.json();
        if (typeof data.html !== 'string' || typeof data.revision !== 'string') throw new Error('Invalid response');
        const focused = document.activeElement;
        const card = focused.closest('[data-photo-id]');
        const cardId = card?.dataset.photoId;
        const controlIndex = card ? [...card.querySelectorAll('a,button,input')].indexOf(focused) : -1;
        const toolbarKey = ['data-select-all', 'data-clear-selection'].find(key => focused.hasAttribute(key));
        const canRestore = !liveContent.contains(focused) || (cardId && controlIndex >= 0) || toolbarKey;
        if (data.revision !== revision && canRestore && !liveContent.querySelector('dialog[open]')) {
          const selected = new Set([...liveContent.querySelectorAll('input[name="photos[]"]:checked')].map(input => input.value));
          liveContent.innerHTML = data.html;
          liveContent.querySelectorAll('input[name="photos[]"]').forEach(input => { input.checked = selected.has(input.value); });
          if (liveContent.hasAttribute('data-gallery')) initializeGallery(liveContent);
          if (cardId && controlIndex >= 0) {
            const replacement = liveContent.querySelector(`[data-photo-id="${CSS.escape(cardId)}"]`);
            replacement?.querySelectorAll('a,button,input')[controlIndex]?.focus({ preventScroll: true });
          } else if (toolbarKey) liveContent.querySelector(`[${toolbarKey}]`)?.focus({ preventScroll: true });
          revision = data.revision;
        }
        const count = document.querySelector('[data-photo-count]');
        if (count && Number.isInteger(data.photo_count)) count.textContent = data.photo_count;
        const usage = document.querySelector('[data-storage-usage]');
        if (usage && data.usage) usage.textContent = data.usage;
        document.querySelector('[data-live-status]').textContent = 'Up to date · updates automatically.';
      } catch {
        document.querySelector('[data-live-status]').textContent = 'Unable to refresh. Reconnecting; you can also refresh this page.';
      }
    }
    if (!stopped) timer = setTimeout(refreshLiveContent, 5000);
  }
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; refreshLiveContent(); } });
  timer = setTimeout(refreshLiveContent, 3000);
}

const exportPanel = document.querySelector('[data-export]');
if (exportPanel) {
  const titles = { queued: 'Waiting to start', processing: 'Building your ZIP', ready: 'Your ZIP is ready', failed: 'Unable to prepare this ZIP', expired: 'This ZIP has expired' };
  const messages = { queued: 'Your request is saved. You can leave this page and return later.', processing: 'Gathering your original photos. This page updates automatically.', ready: 'Your original photos are ready to download.', failed: 'Retry, or return to the gallery if a selected photo was removed.', expired: 'Request a new copy to download these photos again.' };
  let stopped = false;
  let exportTimer;
  async function updateExport() {
    if (stopped) return;
    try {
      const response = await fetch(exportPanel.dataset.statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(15000) });
      if (!response.ok) throw new Error('Status unavailable');
      const { data } = await response.json();
      if (!Object.hasOwn(titles, data.status)) throw new Error('Invalid status');
      exportPanel.dataset.status = data.status;
      exportPanel.querySelector('[data-export-title]').textContent = titles[data.status];
      const message = exportPanel.querySelector('[data-export-message]');
      if (message.textContent !== messages[data.status]) message.textContent = messages[data.status];
      const download = exportPanel.querySelector('[data-export-download]');
      download.hidden = data.status !== 'ready';
      download.href = data.download_url || '#';
      exportPanel.querySelector('[data-export-retry]').hidden = !['failed', 'expired'].includes(data.status);
      exportPanel.querySelector('[data-export-connection]').textContent = '';
    } catch {
      exportPanel.querySelector('[data-export-connection]').textContent = 'Status could not be refreshed. Reconnecting, or use Refresh status.';
    }
    if (!stopped) exportTimer = setTimeout(updateExport, ['queued', 'processing'].includes(exportPanel.dataset.status) ? 2000 : 15000);
  }
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(exportTimer); });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; updateExport(); } });
  exportTimer = setTimeout(updateExport, 1000);
}
