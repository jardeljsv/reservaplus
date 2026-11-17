/* Progressive enhancement only: the resource form is also usable without JavaScript. */
(() => {
  'use strict';
  document.querySelectorAll('[data-resource-editor]').forEach((form) => {
    if (form.dataset.bound) return;
    form.dataset.bound = '1';
    form.classList.add('is-enhanced');
    form.noValidate = true;
    const steps = [...form.querySelectorAll('[data-resource-step]')];
    const stepField = form.elements.namedItem('_step');
    const openStep = (index, focus = false) => {
      const target = steps.find((step) => step.dataset.resourceStep === String(index));
      if (!target) return;
      steps.forEach((step) => { step.open = step === target; });
      if (stepField) stepField.value = String(index);
      form.querySelectorAll('.rp-ra-step-nav [data-ra-go]').forEach((button) => {
        if (button.dataset.raGo === String(index)) button.setAttribute('aria-current', 'step');
        else button.removeAttribute('aria-current');
      });
      if (focus) target.querySelector('summary')?.focus();
    };
    const kindField = form.elements.namedItem('kind');
    const refresh = () => {
      const kind = kindField?.value || form.dataset.initialKind;
      form.querySelectorAll('[data-ra-kind]').forEach((fieldset) => {
        const visible = fieldset.dataset.raKind.split(' ').includes(kind);
        fieldset.hidden = !visible;
        fieldset.disabled = !visible;
      });
      const approval = form.querySelector('[data-ra-approval]');
      if (approval) approval.hidden = !form.elements.namedItem('approval_required')?.checked;
      const approvalMode = form.elements.namedItem('approval_mode')?.value || 'legacy';
      form.querySelectorAll('[data-ra-approval-mode]').forEach((section) => {
        const visible = section.dataset.raApprovalMode === approvalMode;
        section.hidden = !visible;
        if (section.tagName === 'FIELDSET') section.disabled = !visible;
      });
      form.querySelectorAll('[data-ra-default-kind]').forEach((section) => {
        section.hidden = section.dataset.raDefaultKind !== kind;
      });
      const reason = form.querySelector('[data-ra-condition-reason]');
      const changed = form.elements.namedItem('operational_state')?.value !== form.dataset.originalCondition;
      if (reason) {
        reason.hidden = !changed;
        const field = reason.querySelector('input');
        if (field) field.required = changed;
      }
      form.querySelectorAll('[data-ra-preview]').forEach((node) => {
        const field = form.elements.namedItem(node.dataset.raPreview);
        if (!field) return;
        node.textContent = field.tagName === 'SELECT' ? field.selectedOptions[0]?.textContent || '' : field.value || 'Novo recurso';
      });
    };
    form.querySelectorAll('[data-ra-go]').forEach((button) => button.addEventListener('click', () => openStep(button.dataset.raGo, true)));
    steps.forEach((step) => step.querySelector('summary')?.addEventListener('click', (event) => {
      event.preventDefault();
      openStep(step.dataset.resourceStep);
    }));
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    const rows = form.querySelector('[data-ra-accessories]');
    const renumber = () => rows?.querySelectorAll('[data-ra-accessory]').forEach((row, index) => {
      const remove = row.querySelector('[data-ra-remove]');
      if (remove) remove.value = `remove_accessory:${index}`;
      row.querySelectorAll('input').forEach((input) => {
        const suffix = input.name === 'accessory_names[]' ? 'name' : 'quantity';
        const previous = input.id;
        input.id = `rp-accessory-${suffix}-${index}`;
        const label = row.querySelector(`label[for="${CSS.escape(previous)}"]`);
        if (label) label.htmlFor = input.id;
      });
    });
    form.querySelector('[data-ra-add]')?.addEventListener('click', (event) => {
      event.preventDefault();
      if (!rows || rows.children.length >= 100) return;
      const row = rows.querySelector('[data-ra-accessory]')?.cloneNode(true);
      if (!row) return;
      row.querySelectorAll('input').forEach((input) => { input.value = input.name === 'accessory_quantities[]' ? '1' : ''; });
      rows.append(row); renumber(); row.querySelector('input')?.focus();
    });
    rows?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-ra-remove]');
      if (!button) return;
      event.preventDefault();
      const row = button.closest('[data-ra-accessory]');
      if (rows.children.length > 1) row.remove();
      else row.querySelectorAll('input').forEach((input) => { input.value = input.name === 'accessory_quantities[]' ? '1' : ''; });
      renumber();
    });
    form.addEventListener('submit', (event) => {
      if (event.submitter?.formNoValidate) return;
      refresh();
      const invalid = [...form.querySelectorAll('input,select,textarea')].find((field) => !field.disabled && !field.checkValidity());
      if (invalid) {
        event.preventDefault();
        const step = invalid.closest('[data-resource-step]');
        if (step) openStep(step.dataset.resourceStep);
        let detail = invalid.closest('details');
        while (detail && form.contains(detail)) { detail.open = true; detail = detail.parentElement.closest('details'); }
        invalid.focus(); invalid.reportValidity();
      }
    });
    refresh(); renumber(); openStep(stepField?.value || '1');
  });
})();
