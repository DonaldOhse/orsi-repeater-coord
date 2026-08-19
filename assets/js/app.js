/* Oklahoma Repeater Coordination - app.js */

// Auto-dismiss alerts after 6s
document.querySelectorAll('.alert').forEach(el => {
  // Don't auto-dismiss alerts inside tables (frequency conflict warnings, recommendations)
  if (el.closest('td')) return;
  setTimeout(() => { el.style.transition='opacity .5s'; el.style.opacity='0'; setTimeout(()=>el.remove(),500); }, 60000);
});

// Confirm dangerous actions
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => {
    if (!confirm(el.dataset.confirm)) e.preventDefault();
  });
});

// Sortable table headers - preserve query params
document.querySelectorAll('th.sortable a').forEach(a => {
  a.style.cursor = 'pointer';
});

// Toggle checkboxes helper
function toggleAll(src, name) {
  document.querySelectorAll(`input[name="${name}"]`).forEach(cb => cb.checked = src.checked);
}
