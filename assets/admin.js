(() => {
  'use strict';
  document.querySelectorAll('[data-confirm]').forEach(button => button.addEventListener('click', event => {
    if (!window.confirm(button.dataset.confirm)) event.preventDefault();
  }));
  const audience = document.querySelector('[data-audience]');
  if (!audience) return;
  const select = document.querySelector('[data-people-select]');
  const search = document.querySelector('[data-people-search]');
  const status = document.querySelector('[data-people-status]');
  const update = () => {
    for (const key of ['people', 'roles', 'all']) document.querySelector('[data-audience-' + key + ']').hidden = audience.value !== key;
    select.required = audience.value === 'people';
    document.querySelector('[name="confirm_all"]').required = audience.value === 'all';
  };
  audience.addEventListener('change', update); update();
  let timer, abort;
  search.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      if (abort) abort.abort();
      abort = new AbortController();
      try {
        status.textContent = 'Buscando…';
        const url = new URL(PortalPushAdmin.api + 'people-search'); url.searchParams.set('search', search.value);
        const response = await fetch(url.href, { signal: abort.signal, credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': PortalPushAdmin.nonce } });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Não foi possível buscar.');
        const selected = Array.from(select.selectedOptions).map(option => ({ id: String(option.value), name: option.textContent }));
        const known = new Set(selected.map(person => person.id));
        select.replaceChildren();
        for (const person of selected) select.add(new Option(person.name, person.id, true, true));
        for (const person of data.people) if (!known.has(String(person.id))) select.add(new Option(person.name, String(person.id)));
        status.textContent = data.people.length ? '' : 'Nenhuma pessoa encontrada.';
      } catch (error) { if (error.name !== 'AbortError') status.textContent = error.message; }
    }, 300);
  });
})();
