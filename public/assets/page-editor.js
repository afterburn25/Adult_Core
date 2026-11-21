/* Private unsaved page preview. Never sends data, stores a draft,
   interprets HTML, or changes the server-controlled publish state. */
(() => {
  'use strict';
  const titleField = document.getElementById('page-title');
  const bodyField = document.getElementById('page-body');
  const titlePreview = document.getElementById('page-live-title');
  const bodyPreview = document.getElementById('page-live-body');
  if (!titleField || !bodyField || !titlePreview || !bodyPreview) return;
  const sync = () => {
    titlePreview.textContent = titleField.value;
    bodyPreview.textContent = bodyField.value;
  };
  titleField.addEventListener('input', sync);
  bodyField.addEventListener('input', sync);
  sync();
})();
