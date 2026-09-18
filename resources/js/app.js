document.querySelector('[data-menu]')?.addEventListener('click', () => document.querySelector('#sidebar')?.classList.toggle('open'));
document.addEventListener('click', event => {
    if (!event.target.closest('#sidebar') && !event.target.closest('[data-menu]')) document.querySelector('#sidebar')?.classList.remove('open');
});
// Prevent accidental double submission. The backend independently enforces call idempotency.
document.querySelectorAll('form[method="post"]').forEach(form => form.addEventListener('submit', () => {
    if (!form.checkValidity()) return;
    requestAnimationFrame(() => form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    }));
}));
window.addEventListener('pageshow', () => document.querySelectorAll('button[aria-busy]').forEach(button => {
    button.disabled = false;
    button.removeAttribute('aria-busy');
}));
