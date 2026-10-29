(() => {
  const button = document.querySelector('.nf-menu-toggle');
  const menu = document.getElementById('nf-menu');
  const mobile = window.matchMedia('(max-width: 780px)');
  function closeMenu() {
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', 'Abrir menú');
    menu.hidden = mobile.matches;
  }
  button.addEventListener('click', () => {
    const open = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(open));
    button.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
    menu.hidden = !open;
  });
  menu.addEventListener('click', (event) => {
    if (event.target.closest('a')) closeMenu();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
      closeMenu();
      button.focus();
    }
  });
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.nf-header')) closeMenu();
  });
  mobile.addEventListener('change', closeMenu);
  closeMenu();
})();
