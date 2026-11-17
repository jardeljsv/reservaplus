(() => {
  'use strict';
  const root = document.querySelector('[data-testid="request-intake"]');
  const form = root?.querySelector('[data-rp-intake-form]');
  if (!form || !root) return;
  const list = root.querySelector('[data-cart-list]');
  const count = root.querySelector('[data-cart-count]');
  const empty = root.querySelector('[data-cart-empty]');
  const checks = [...form.querySelectorAll('[data-resource-card] input[type="checkbox"]')];
  const cards = new Map(checks.map(input => [input.value, input.closest('[data-resource-card]')]));
  const update = () => {
    if (!list || !count) return;
    checks.forEach(input => {
      const card = cards.get(input.value);
      card.classList.toggle('is-selected', input.checked);
      let item = list.querySelector(`[data-cart-item="${input.value}"]`);
      if (input.checked && !item) {
        item = document.createElement('li');
        item.dataset.cartItem = input.value;
        const text = document.createElement('div');
        const name = document.createElement('strong');
        name.textContent = card.dataset.resourceName || 'Recurso selecionado';
        text.append(name);
        const remove = document.createElement('button');
        remove.type = 'submit'; remove.name = 'intent'; remove.value = `remove:${input.value}`;
        remove.className = 'rp-cart-remove'; remove.setAttribute('aria-label', `Remover ${name.textContent}`);
        remove.innerHTML = '<i class="ti ti-x" aria-hidden="true"></i>';
        item.append(text, remove); list.append(item);
      } else if (!input.checked && item) item.remove();
    });
    const total = list.querySelectorAll('[data-cart-item]').length;
    count.textContent = String(total);
    if (empty) empty.hidden = total > 0;
  };
  checks.forEach(input => input.addEventListener('change', update));
  list?.addEventListener('click', event => {
    const remove = event.target.closest('button[value^="remove:"]');
    if (!remove) return;
    const id = remove.value.slice(7);
    const input = checks.find(c => c.value === id);
    if (input) { event.preventDefault(); input.checked = false; update(); }
  });
  form.querySelector('[data-testid="intake-select-visible"]')?.addEventListener('click', event => {
    event.preventDefault();
    const outside = [...(list?.querySelectorAll('[data-cart-item]') || [])].filter(li => !cards.has(li.dataset.cartItem)).length;
    let selected = outside;
    checks.forEach(input => {
      if (!input.disabled && selected < 32) { input.checked = true; selected++; }
      else if (input.checked) selected++;
    });
    update();
  });
  for (const name of ['begin', 'end', 'people_count']) {
    form.querySelector(`[name="${name}"]`)?.addEventListener('change', () => {
      root.querySelectorAll('.rp-catalog-card .rp-availability').forEach(status => {
        status.classList.remove('is-available', 'is-busy');
        status.classList.add('is-stale');
        status.textContent = 'Atualize a consulta para este período';
      });
      form.querySelector('[data-testid="intake-filter"]')?.classList.add('needs-refresh');
    });
  }
  update();
})();
