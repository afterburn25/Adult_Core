/* Adult Core Visual Design Studio: live plaintext, themes and pointer/keyboard cutout placement.
   No external libraries or inline scripts; server validates and persists every value. */
(() => {
  'use strict';
  const form = document.getElementById('design-form');
  const canvas = document.getElementById('studio-canvas');
  const visual = document.getElementById('studio-canvas-visual');
  if (!form || !canvas || !visual) return;

  const titleField = document.getElementById('studio-title');
  const bodyField = document.getElementById('studio-body');
  const title = document.getElementById('visual-title');
  const body = document.getElementById('visual-body');
  const accent = document.getElementById('design-accent');
  const imageSide = document.getElementById('image-side');
  const imageSize = document.getElementById('image-size');
  const status = document.getElementById('studio-drag-status');
  const sliders = {
    x: document.getElementById('cutout_x'),
    y: document.getElementById('cutout_y'),
    width: document.getElementById('cutout_width'),
  };
  if (!sliders.x || !sliders.y || !sliders.width) return;
  const allowed = ['noir-gold', 'velvet-plum', 'midnight-teal', 'rose-ivory'];
  let syncing = false;
  let placement = null;

  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
  const number = (field) => Number.parseInt(field.value, 10);
  const positioner = () => document.getElementById('studio-cutout');
  const writeStatus = (message) => { if (status) status.textContent = message; };

  // Public CSS uses the identical percentage variables. Inputs are saved by ordinary form POST.
  const drawPlacement = () => {
    const x = clamp(number(sliders.x) || 0, 0, 100);
    const y = clamp(number(sliders.y) || 0, 0, 100);
    const width = clamp(number(sliders.width) || 80, 20, 100);
    visual.style.setProperty('--cutout-x', x + '%');
    visual.style.setProperty('--cutout-y', y + '%');
    visual.style.setProperty('--cutout-width', width + '%');
    for (const [key, value] of Object.entries({cutout_x:x, cutout_y:y, cutout_width:width})) {
      const output = document.getElementById('out-' + key);
      if (output) output.textContent = value + '%';
    }
  };

  // Allow deliberate edge bleed/cropping, as in a normal visual design editor.
  // Slider controls always provide a reliable way to bring an image back into view.
  const boundedPosition = (x, y) => ({x:clamp(x,0,100), y:clamp(y,0,100)});

  const setPlacement = (x, y, width, bounded = false) => {
    sliders.width.value = String(clamp(Math.round(width), 20, 100));
    // Draw width first so position clamping accounts for the resized image.
    drawPlacement();
    if (bounded) ({x, y} = boundedPosition(x, y));
    sliders.x.value = String(clamp(Math.round(x),0,100));
    sliders.y.value = String(clamp(Math.round(y),0,100));
    drawPlacement();
  };

  const beginPointer = (event, mode) => {
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    const target = event.currentTarget;
    const img = positioner();
    if (!img) return;
    const frame = visual.getBoundingClientRect();
    if (!frame.width || !frame.height) return;
    const initial = {pageX:event.clientX, pageY:event.clientY,
      x:number(sliders.x), y:number(sliders.y), width:number(sliders.width)};
    event.preventDefault();
    event.stopPropagation();
    target.setPointerCapture?.(event.pointerId);
    img.classList.add('is-manipulating');
    const update = (move) => {
      if (move.pointerId !== event.pointerId) return;
      const dx = (move.clientX - initial.pageX) / frame.width * 100;
      const dy = (move.clientY - initial.pageY) / frame.height * 100;
      if (mode === 'resize') {
        const delta = Math.abs(dx) >= Math.abs(dy) ? dx : dy;
        setPlacement(initial.x,initial.y,initial.width + delta,true);
      } else {
        setPlacement(initial.x + dx,initial.y + dy,initial.width,true);
      }
      writeStatus('Position ' + sliders.x.value + '% × ' + sliders.y.value + '% · width ' + sliders.width.value + '% — save to publish changes.');
    };
    const finish = (done) => {
      if (done.pointerId !== event.pointerId) return;
      img.classList.remove('is-manipulating');
      target.removeEventListener('pointermove',update);
      target.removeEventListener('pointerup',finish);
      target.removeEventListener('pointercancel',finish);
      if (target.hasPointerCapture?.(event.pointerId)) target.releasePointerCapture(event.pointerId);
    };
    target.addEventListener('pointermove',update);
    target.addEventListener('pointerup',finish);
    target.addEventListener('pointercancel',finish);
  };

  const keys = (event, mode) => {
    if (!['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key)) return;
    event.preventDefault();
    const step = event.shiftKey ? 10 : 1;
    const dx = event.key === 'ArrowRight' ? step : event.key === 'ArrowLeft' ? -step : 0;
    const dy = event.key === 'ArrowDown' ? step : event.key === 'ArrowUp' ? -step : 0;
    if (mode === 'resize') setPlacement(number(sliders.x),number(sliders.y),number(sliders.width) + (dx || dy),true);
    else setPlacement(number(sliders.x) + dx,number(sliders.y) + dy,number(sliders.width),true);
    writeStatus('Position ' + sliders.x.value + '% × ' + sliders.y.value + '% · width ' + sliders.width.value + '%.');
  };

  const makeCutout = (image) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'cutout-positioner';
    wrapper.id = 'studio-cutout';
    const picture = image.cloneNode(true);
    picture.className = 'design-portrait';
    picture.alt = 'Selected cutout; move or resize using the visible handles';
    picture.draggable = false;
    const move = document.createElement('button');
    move.type = 'button'; move.className = 'cutout-move-handle';
    move.setAttribute('aria-label','Drag cutout to move; arrow keys also work');
    move.textContent = 'Move ✥';
    const resize = document.createElement('button');
    resize.type = 'button'; resize.className = 'cutout-resize-handle';
    resize.setAttribute('aria-label','Drag corner to resize cutout; arrow keys also work');
    resize.textContent = '↘';
    wrapper.append(picture,move,resize);
    visual.replaceChildren(wrapper);
    attachCutout(wrapper);
    drawPlacement();
  };

  function attachCutout(wrapper) {
    if (!wrapper) return;
    wrapper.addEventListener('pointerdown',(event) => {
      if (event.target.closest('button')) return;
      beginPointer(event,'move');
    });
    const move = wrapper.querySelector('.cutout-move-handle');
    const resize = wrapper.querySelector('.cutout-resize-handle');
    move?.addEventListener('pointerdown',(event)=>beginPointer(event,'move'));
    resize?.addEventListener('pointerdown',(event)=>beginPointer(event,'resize'));
    move?.addEventListener('keydown',(event)=>keys(event,'move'));
    resize?.addEventListener('keydown',(event)=>keys(event,'resize'));
  }

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
  for (const input of Object.values(sliders)) {
    input.addEventListener('input',() => {
      setPlacement(number(sliders.x),number(sliders.y),number(sliders.width));
      writeStatus('Live cutout position and width updated; click Save design changes to keep them.');
    });
  }
  document.getElementById('cutout-reset')?.addEventListener('click',() => {
    setPlacement(50,50,80);
    writeStatus('Cutout centered and restored to 80% width. Save to keep this placement.');
  });
  document.getElementById('studio-change-image')?.addEventListener('click', () => {
    const upload = document.getElementById('design-upload');
    if (upload) { upload.scrollIntoView({behavior:'smooth',block:'center'}); upload.click(); }
  });
  form.querySelectorAll('input[name="hero_asset"]').forEach(r => r.addEventListener('change', () => {
    if (!r.checked) return;
    const chosen = r.closest('.asset-choice')?.querySelector('img');
    if (chosen) {
      makeCutout(chosen);
      writeStatus('Drag the photo or the Move handle; drag ↘ to resize it live.');
    } else {
      const placeholder = document.createElement('span');
      placeholder.className = 'studio-preview-placeholder';
      placeholder.textContent = '+ Add your cutout photo';
      const note = document.createElement('small');
      note.textContent = 'Theme background stays in place';
      placeholder.appendChild(note);
      visual.replaceChildren(placeholder);
      writeStatus('No cutout selected; the theme background remains visible.');
    }
  }));
  for (const [node,field,multiline] of [[title,titleField,false],[body,bodyField,true]]) {
    node.addEventListener('input', () => {
      syncing = true;
      field.value = multiline ? node.innerText.replace(/\r/g,'') : node.textContent.replace(/[\r\n]+/g,' ');
      syncing = false;
    });
    field.addEventListener('input', () => drawText(field,node,multiline));
  }
  form.addEventListener('submit', () => {
    titleField.value = title.textContent.replace(/[\r\n]+/g,' ');
    bodyField.value = body.innerText.replace(/\r/g,'');
  });
  canvas.style.setProperty('--design-accent', accent.value);
  attachCutout(positioner());
  drawPlacement();
})();
