(function () {
  'use strict';
  var months = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  var weekdays = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
  function dayLabel(key) {
    var parts = key.split('-').map(Number), date = new Date(parts[0], parts[1] - 1, parts[2]);
    return weekdays[date.getDay()] + ', ' + parts[2] + ' de ' + months[parts[1] - 1];
  }
  function dayEnd(key) {
    var parts = key.split('-').map(Number), date = new Date(parts[0], parts[1] - 1, parts[2] + 1);
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0') + ' 00:00:00';
  }
  function timeLabel(event, key) {
    return (event.start.slice(0, 10) < key ? 'Continua' : event.start.slice(11, 16)) + ' – ' + (event.end.slice(0, 10) > key ? 'dia seguinte' : event.end.slice(11, 16));
  }
  function el(tag, className, text) {
    var node = document.createElement(tag); if (className) node.className = className;
    if (text) node.textContent = text; return node;
  }
  document.querySelectorAll('[data-rp-calendar]').forEach(function (calendar) {
    var configNode = calendar.querySelector('[data-rp-calendar-data]');
    if (!configNode) return;
    var config;
    try { config = JSON.parse(configNode.textContent); } catch (error) { return; }
    var form = calendar.querySelector('[data-rp-calendar-filters]');
    if (form) {
      var kind = form.elements.kind;
      kind.addEventListener('change', function () { form.elements.resources_id.value = '0'; form.requestSubmit(); });
      ['resources_id', 'scope', 'status'].forEach(function (name) {
        form.elements[name].addEventListener('change', function () { form.requestSubmit(); });
      });
      form.addEventListener('submit', function () { form.classList.add('is-submitting'); });
    }
    function select(key, url) {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(key)) return;
      var list = calendar.querySelector('[data-rp-calendar-day-events]');
      if (!list) return;
      var events = config.events.filter(function (event) { return event.start < dayEnd(key) && event.end > key + ' 00:00:00'; });
      list.replaceChildren(); list.scrollTop = 0;
      if (!events.length) {
        var empty = el('div', 'rp-cal-empty');
        var icon = el('i', 'ti ti-calendar-search'); icon.setAttribute('aria-hidden', 'true'); empty.appendChild(icon);
        empty.appendChild(el('strong', '', 'Nenhum evento neste filtro'));
        empty.appendChild(el('p', '', 'Escolha recursos e horários para confirmar a disponibilidade.')); list.appendChild(empty);
      }
      events.forEach(function (event) {
        var card = el('article', 'rp-cal-detail rp-cal-detail--' + event.type);
        card.appendChild(el('span', 'rp-cal-detail-time', timeLabel(event, key)));
        card.appendChild(el('h3', '', event.title)); card.appendChild(el('span', 'rp-cal-pill', event.label));
        if (event.location) card.appendChild(el('p', 'rp-cal-location', event.location));
        card.appendChild(el('p', '', event.detail));
        if (event.url) { var link = el('a', '', 'Abrir solicitação →'); link.href = event.url; card.appendChild(link); }
        list.appendChild(card);
      });
      calendar.querySelector('[data-rp-calendar-selected-label]').textContent = dayLabel(key);
      calendar.querySelectorAll('[data-rp-calendar-cell]').forEach(function (node) { node.classList.toggle('is-selected', node.dataset.rpCalendarCell === key); });
      calendar.querySelectorAll('[data-rp-calendar-day]').forEach(function (node) {
        if (node.dataset.rpCalendarDay === key) node.setAttribute('aria-current', 'date'); else node.removeAttribute('aria-current');
      });
      var request = calendar.querySelector('[data-rp-calendar-request]');
      if (request) { var requestUrl = new URL(config.request_url, window.location.href); requestUrl.searchParams.set('begin', key + 'T08:00'); requestUrl.searchParams.set('end', key + 'T09:00'); request.href = requestUrl.toString(); }
      calendar.querySelectorAll('[data-rp-calendar-view]').forEach(function (link) {
        var target = new URL(link.href); target.searchParams.set('date', key); target.searchParams.set('selected', key); link.href = target.toString();
      });
      config.state.selected = key;
      if (url) window.history.replaceState(null, '', url);
    }
    calendar.addEventListener('click', function (event) {
      var link = event.target.closest('[data-rp-calendar-day]');
      if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); select(link.dataset.rpCalendarDay, link.href);
      if (window.matchMedia('(max-width: 650px)').matches) calendar.querySelector('.rp-cal-agenda').scrollIntoView({behavior:'smooth',block:'start'});
    });
    // Server-rendered GET links remain authoritative; this enhancement only changes the selected-day panel.
    window.addEventListener('popstate', function () {
      var url = new URL(window.location.href), selected = url.searchParams.get('selected');
      if (selected) select(selected, null);
    });
  });
})();
