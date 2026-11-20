/* Visual Design Studio enhancement. The ordinary form remains usable without JavaScript. */
(() => {
  'use strict';
  const form = document.getElementById('design-form');
  const canvas = document.getElementById('studio-canvas');
  if (!form || !canvas) return;
  const titleField = document.getElementById('studio-title');
  const bodyField = document.getElementById('studio-body');
  const title = document.getElementById('visual-title');
  const body = document.getElementById('visual-body');
  const accent = document.getElementById('design-accent');
  const visual = document.getElementById('studio-canvas-visual');
  const imageSide = document.getElementById('image-side');
  const imageSize = document.getElementById('image-size');
  const allowed = ['noir-gold', 'velvet-plum', 'midnight-teal', 'rose-ivory'];
  let syncing = false;
  const drawText = (field, node, multiline) => {
    if (syncing || document.activeElement === node) return;
    node.textContent = field.value;
    node.classList.toggle('has-linebreaks', multiline);
  };
  const themeChange = () => {
    const chosen = form.querySelector('input[name="theme"]:checked');
    if (!chosen || !allowed.includes(chosen.value)) return;
    for (const cls of allowed) canvas.classList.remove('theme-' + cls);
    canvas.classList.add('theme-' + chosen.value);
    if (chosen.dataset.accent) {
      accent.value = chosen.dataset.accent;
      canvas.style.setProperty('--design-accent', accent.value);
    }
  };
  form.querySelectorAll('input[name="theme"]').forEach(r => r.addEventListener('change', themeChange));
  accent.addEventListener('input', () => {
    if (/^#[0-9a-f]{6}$/i.test(accent.value)) canvas.style.setProperty('--design-accent', accent.value);
  });
  imageSide.addEventListener('change', () => {
    canvas.classList.remove('image-left', 'image-right');
    canvas.classList.add('image-' + imageSide.value);
  });
  imageSize.addEventListener('change', () => {
    canvas.classList.remove('image-size-small', 'image-size-medium', 'image-size-large');
    canvas.classList.add('image-size-' + imageSize.value);
  });
  document.getElementById('studio-change-image')?.addEventListener('click', () => {
    const upload = document.getElementById('design-upload');
    if (upload) { upload.scrollIntoView({behavior:'smooth',block:'center'}); upload.click(); }
  });
  form.querySelectorAll('input[name="hero_asset"]').forEach(r => r.addEventListener('change', () => {
    if (!r.checked) return;
    // Safe: clone a local preview image from the authorized asset library.
    visual.replaceChildren();
    const choice = r.closest('.asset-choice');
    const image = choice?.querySelector('img');
    if (image) {
      const clone = image.cloneNode(true);
      clone.className = 'design-portrait';
      clone.alt = 'Chosen photo preview';
      visual.appendChild(clone);
    } else {
      const placeholder = document.createElement('span');
      placeholder.className = 'studio-preview-placeholder';
      placeholder.textContent = '✦';
      visual.appendChild(placeholder);
    }
  }));
  for (const [node, field, multiline] of [[title,titleField,false],[body,bodyField,true]]) {
    node.addEventListener('input', () => {
      syncing = true;
      field.value = multiline ? node.innerText.replace(/\r/g, '') : node.textContent.replace(/[\r\n]+/g, ' ');
      syncing = false;
    });
    field.addEventListener('input', () => drawText(field,node,multiline));
  }
  form.addEventListener('submit', () => {
    // Only plaintext is submitted, regardless of the browser's contenteditable implementation.
    titleField.value = title.textContent.replace(/[\r\n]+/g, ' ');
    bodyField.value = body.innerText.replace(/\r/g, '');
  });
  // The selected color is persisted server-side; this only changes admin preview appearance.
  canvas.style.setProperty('--design-accent', accent.value);
})();
