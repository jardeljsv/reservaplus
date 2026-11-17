(function () {
  // --- Utilities ---

  function toDateKey(date) {
    return [
      date.getFullYear(),
      String(date.getMonth() + 1).padStart(2, "0"),
      String(date.getDate()).padStart(2, "0"),
    ].join("-");
  }

  function monthLabel(date) {
    return date.toLocaleDateString("pt-BR", {
      month: "long",
      year: "numeric",
    });
  }

  function eventOverlapsDay(event, key) {
    var start = new Date(String(event.start || "").replace(" ", "T"));
    var end = new Date(String(event.end || "").replace(" ", "T"));
    var dayStart = new Date(key + "T00:00:00");
    var dayEnd = new Date(dayStart);
    dayEnd.setDate(dayEnd.getDate() + 1);
    return start < dayEnd && end > dayStart;
  }

  function eventCssClass(event) {
    if (event.type === "request" && event.status) {
      return "request " + event.status;
    }
    return event.type || "request";
  }

  function formatTime(datetime) {
    if (!datetime) return "";
    var m = String(datetime).match(/(\d{2}):(\d{2})/);
    return m ? m[1] + ":" + m[2] : "";
  }

  function formatDateTimeLocal(date) {
    return (
      [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, "0"),
        String(date.getDate()).padStart(2, "0"),
      ].join("-") +
      "T" +
      [
        String(date.getHours()).padStart(2, "0"),
        String(date.getMinutes()).padStart(2, "0"),
      ].join(":")
    );
  }

  function defaultReservationHours(dateKey) {
    var selected = new Date(dateKey + "T09:00:00");
    var now = new Date();
    if (dateKey === toDateKey(now) && now.getHours() >= 9) {
      selected = new Date(now);
      selected.setMinutes(0, 0, 0);
      selected.setHours(selected.getHours() + 1);
    }
    var end = new Date(selected);
    end.setHours(selected.getHours() + 1);
    return { begin: selected, end: end };
  }

  function statusLabel(status) {
    var labels = {
      created: "Ativa",
      cancelled: "Cancelada",
    };
    return labels[status] || status;
  }

  function statusBadgeClass(status) {
    var map = {
      created: "reservaplus-badge-approved",
      cancelled: "",
    };
    return map[status] || "";
  }

  // --- Month view rendering ---

  function renderCalendar(calendar, currentDate, events) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");
    var weekdays = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
    var first = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
    var start = new Date(first);
    start.setDate(first.getDate() - first.getDay());

    title.textContent = monthLabel(currentDate);
    calendar.classList.remove("is-loading");
    grid.className = "reservaplus-calendar-grid";
    grid.innerHTML = "";

    weekdays.forEach(function (day) {
      var cell = document.createElement("div");
      cell.className = "reservaplus-calendar-weekday";
      cell.textContent = day;
      grid.appendChild(cell);
    });

    for (var index = 0; index < 42; index += 1) {
      var date = new Date(start);
      date.setDate(start.getDate() + index);

      var key = toDateKey(date);
      var isMuted = date.getMonth() !== currentDate.getMonth();
      var todayKey = toDateKey(new Date());

      var day = document.createElement("div");
      day.className =
        "reservaplus-calendar-day" +
        (isMuted ? " is-muted" : "") +
        (key === todayKey ? " is-today" : "");
      day.setAttribute("data-reservaplus-day", key);
      day.setAttribute("role", "button");
      day.setAttribute("tabindex", "0");
      day.setAttribute(
        "aria-label",
        "Ver reservas de " + date.toLocaleDateString("pt-BR")
      );

      var number = document.createElement("div");
      number.className = "reservaplus-calendar-day-number";
      number.textContent = String(date.getDate());
      day.appendChild(number);

      var dayEvents = events.filter(function (event) {
        return eventOverlapsDay(event, key);
      });

      dayEvents.slice(0, 3).forEach(function (event) {
        var item = document.createElement("div");
        item.className = "reservaplus-calendar-event " + eventCssClass(event);
        item.title = event.title || "";
        item.textContent = event.title || "";
        day.appendChild(item);
      });

      if (dayEvents.length > 3) {
        var more = document.createElement("div");
        more.className = "reservaplus-calendar-more";
        more.textContent = "+" + (dayEvents.length - 3) + " mais";
        day.appendChild(more);
      }

      grid.appendChild(day);
    }
  }

  // --- Week view rendering ---

  function renderWeekView(calendar, currentDate, events) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");
    var weekdays = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];

    var weekStart = new Date(currentDate);
    weekStart.setDate(weekStart.getDate() - weekStart.getDay());
    var weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);

    var opts = { day: "2-digit", month: "long", year: "numeric" };
    title.textContent =
      weekStart.toLocaleDateString("pt-BR", opts) +
      " – " +
      weekEnd.toLocaleDateString("pt-BR", opts);

    calendar.classList.remove("is-loading");
    grid.className = "reservaplus-week-grid";
    grid.innerHTML = "";

    var todayKey = toDateKey(new Date());

    for (var i = 0; i < 7; i++) {
      var date = new Date(weekStart);
      date.setDate(weekStart.getDate() + i);
      var key = toDateKey(date);

      var col = document.createElement("div");
      col.className =
        "reservaplus-week-col" + (key === todayKey ? " is-today" : "");
      col.setAttribute("data-reservaplus-day", key);
      col.setAttribute("role", "button");
      col.setAttribute("tabindex", "0");
      col.setAttribute("aria-label", "Ver reservas de " + date.toLocaleDateString("pt-BR"));

      var header = document.createElement("div");
      header.className = "reservaplus-week-col-header";

      var dayname = document.createElement("span");
      dayname.className = "reservaplus-week-dayname";
      dayname.textContent = weekdays[i];

      var daynum = document.createElement("span");
      daynum.className = "reservaplus-week-daynum";
      daynum.textContent = String(date.getDate());

      header.appendChild(dayname);
      header.appendChild(daynum);
      col.appendChild(header);

      var dayEvents = events.filter(function (e) {
        return eventOverlapsDay(e, key);
      });
      dayEvents.sort(function (a, b) {
        return String(a.start || "").localeCompare(String(b.start || ""));
      });

      var eventsDiv = document.createElement("div");
      eventsDiv.className = "reservaplus-week-events";

      if (dayEvents.length === 0) {
        var empty = document.createElement("div");
        empty.className = "reservaplus-week-empty";
        var emptyIcon = document.createElement("i");
        emptyIcon.className = "ti ti-calendar-plus";
        var emptyText = document.createElement("span");
        emptyText.textContent = "Livre";
        empty.appendChild(emptyIcon);
        empty.appendChild(emptyText);
        eventsDiv.appendChild(empty);
      } else {
        dayEvents.forEach(function (event) {
          var item = document.createElement("div");
          item.className = "reservaplus-calendar-event " + eventCssClass(event);
          var time = formatTime(event.start);
          item.title = (time ? time + " " : "") + (event.title || "");
          if (time) {
            var timeSpan = document.createElement("span");
            timeSpan.className = "reservaplus-week-event-time";
            timeSpan.textContent = time + " ";
            item.appendChild(timeSpan);
          }
          var titleSpan = document.createElement("span");
          titleSpan.textContent = event.title || "";
          item.appendChild(titleSpan);
          eventsDiv.appendChild(item);
        });
      }

      col.appendChild(eventsDiv);
      grid.appendChild(col);
    }
  }

  // --- Day view rendering ---

  function renderDayView(calendar, currentDate, events) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");

    var key = toDateKey(currentDate);
    title.textContent = currentDate.toLocaleDateString("pt-BR", {
      weekday: "long",
      day: "2-digit",
      month: "long",
      year: "numeric",
    });

    calendar.classList.remove("is-loading");
    grid.className = "reservaplus-day-view";
    grid.innerHTML = "";

    var dayEvents = events.filter(function (e) {
      return eventOverlapsDay(e, key);
    });
    dayEvents.sort(function (a, b) {
      return String(a.start || "").localeCompare(String(b.start || ""));
    });

    if (dayEvents.length === 0) {
      var empty = document.createElement("div");
      empty.className = "reservaplus-dayview-empty";
      var icon = document.createElement("i");
      icon.className = "ti ti-calendar-off";
      var span = document.createElement("span");
      span.textContent = "Nenhuma reserva neste dia.";
      empty.appendChild(icon);
      empty.appendChild(span);
      grid.appendChild(empty);
    } else {
      dayEvents.forEach(function (event) {
        var row = document.createElement("div");
        row.className = "reservaplus-dayview-event " + eventCssClass(event);
        row.setAttribute("data-reservaplus-day", key);
        row.setAttribute("role", "button");
        row.setAttribute("tabindex", "0");

        var timeStart = formatTime(event.start);
        var timeEnd = event.end ? formatTime(event.end) : "";
        var timeText = timeStart + (timeEnd ? " – " + timeEnd : "");

        var timeDiv = document.createElement("div");
        timeDiv.className = "reservaplus-dayview-time";
        timeDiv.textContent = timeText;

        var infoDiv = document.createElement("div");
        infoDiv.className = "reservaplus-dayview-info";
        var strong = document.createElement("strong");
        strong.textContent = event.title || "";
        infoDiv.appendChild(strong);

        row.appendChild(timeDiv);
        row.appendChild(infoDiv);
        grid.appendChild(row);
      });
    }
  }

  // --- Loading state ---

  function setLoadingState(calendar, currentDate) {
    var title = calendar.querySelector(".reservaplus-calendar-title");
    var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");

    calendar.classList.add("is-loading");
    if (title) {
      title.textContent = monthLabel(currentDate);
    }
    if (grid) {
      grid.className = "reservaplus-calendar-grid";
      grid.innerHTML = "";
      for (var i = 0; i < 42; i++) {
        var skeleton = document.createElement("div");
        skeleton.className =
          "reservaplus-calendar-day reservaplus-calendar-skeleton";
        grid.appendChild(skeleton);
      }
    }
  }

  // --- Quick reservation modal ---

  function openQuickReservation(dateKey) {
    var modal = document.querySelector("[data-reservaplus-modal]");
    if (!modal || !dateKey) return;

    var dates = defaultReservationHours(dateKey);
    var beginInput = modal.querySelector("[data-reservaplus-begin]");
    var endInput = modal.querySelector("[data-reservaplus-end]");
    var label = modal.querySelector("[data-reservaplus-modal-date]");
    var firstField = modal.querySelector("select, input, textarea");
    var readableDate = new Date(dateKey + "T12:00:00").toLocaleDateString(
      "pt-BR",
      { weekday: "long", day: "2-digit", month: "long", year: "numeric" }
    );

    if (beginInput) beginInput.value = formatDateTimeLocal(dates.begin);
    if (endInput) endInput.value = formatDateTimeLocal(dates.end);
    if (label) label.textContent = readableDate;

    // Recalcula a disponibilidade ao (re)abrir o modal (mudar .value não dispara eventos)
    if (beginInput) beginInput.dispatchEvent(new Event("change"));

    modal.hidden = false;
    document.body.classList.add("reservaplus-modal-open");
    window.setTimeout(function () {
      if (firstField) firstField.focus();
    }, 50);
  }

  function closeQuickReservation() {
    var modal = document.querySelector("[data-reservaplus-modal]");
    if (!modal) return;
    modal.hidden = true;
    document.body.classList.remove("reservaplus-modal-open");
  }

  // --- Day detail modal ---

  function renderDayDetailEvent(event) {
    var config = window.reservaplusConfig || {};

    var div = document.createElement("div");
    div.className = "reservaplus-daydetail-event " + eventCssClass(event);

    var info = document.createElement("div");
    info.className = "reservaplus-daydetail-event-info";

    var titleEl = document.createElement("strong");
    titleEl.textContent = event.title || "";
    info.appendChild(titleEl);

    if (event.start && event.end) {
      var timeEl = document.createElement("span");
      timeEl.className = "reservaplus-daydetail-event-time";
      timeEl.textContent = formatTime(event.start) + " – " + formatTime(event.end);
      info.appendChild(timeEl);
    }

    if (event.type === "request" && event.status) {
      var badge = document.createElement("span");
      badge.className = "reservaplus-badge " + statusBadgeClass(event.status);
      badge.textContent = statusLabel(event.status);
      info.appendChild(badge);
    }

    div.appendChild(info);

    // Display only actions the server authorized for this exact row.
    if (event.type === "request" && event.requestId) {
      function mkHidden(name, value) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = String(value);
        return input;
      }

      var actions = [];
      if (event.canCancel === true) {
        actions.push({ name: "cancel", title: "Cancelar reserva", confirm: "Cancelar esta reserva?", css: "warning", icon: "ban" });
      }
      if (event.canDelete === true) {
        actions.push({ name: "delete", title: "Apagar reserva", confirm: "Tem certeza que deseja apagar esta reserva?", css: "danger", icon: "trash" });
      }
      actions.forEach(function (action) {
        var form = document.createElement("form");
        form.method = "post";
        form.action = config.deleteActionUrl || "";
        form.className = "reservaplus-inline-form";
        form.appendChild(mkHidden("_glpi_csrf_token", config.csrfToken || ""));
        form.appendChild(mkHidden("id", event.requestId));
        form.appendChild(mkHidden("redirect", "calendar.php"));
        var button = document.createElement("button");
        button.type = "submit";
        button.name = action.name;
        button.value = "1";
        button.className = "btn btn-sm btn-outline-" + action.css;
        button.title = action.title;
        button.innerHTML = "<i class='ti ti-" + action.icon + "'></i>";
        button.addEventListener("click", function (e) {
          if (!window.confirm(action.confirm)) e.preventDefault();
        });
        form.appendChild(button);
        div.appendChild(form);
      });
    }

    return div;
  }

  function openDayDetail(dateKey, events) {
    var detail = document.querySelector("[data-reservaplus-daydetail]");
    if (!detail || !dateKey) return;

    var dayEvents = events.filter(function (e) {
      return eventOverlapsDay(e, dateKey);
    });

    var dateEl = detail.querySelector("[data-reservaplus-daydetail-date]");
    if (dateEl) {
      dateEl.textContent = new Date(
        dateKey + "T12:00:00"
      ).toLocaleDateString("pt-BR", {
        weekday: "long",
        day: "2-digit",
        month: "long",
        year: "numeric",
      });
    }

    var body = detail.querySelector("[data-reservaplus-daydetail-body]");
    if (body) {
      body.innerHTML = "";
      if (dayEvents.length === 0) {
        var empty = document.createElement("div");
        empty.className = "reservaplus-daydetail-empty";
        empty.textContent = "Nenhuma reserva neste dia.";
        body.appendChild(empty);
      } else {
        dayEvents.forEach(function (event) {
          body.appendChild(renderDayDetailEvent(event));
        });
      }
    }

    detail.setAttribute("data-reservaplus-daydetail-datekey", dateKey);
    detail.hidden = false;
    document.body.classList.add("reservaplus-modal-open");
  }

  function closeDayDetail() {
    var detail = document.querySelector("[data-reservaplus-daydetail]");
    if (!detail) return;
    detail.hidden = true;
    document.body.classList.remove("reservaplus-modal-open");
  }

  // --- Calendar init ---

  function initCalendars() {
    document
      .querySelectorAll(".reservaplus-calendar")
      .forEach(function (calendar) {
        var currentDate = new Date();
        var loadedEvents = [];
        var currentView = "month";

        function renderCurrentView() {
          if (currentView === "week") {
            renderWeekView(calendar, currentDate, loadedEvents);
          } else if (currentView === "day") {
            renderDayView(calendar, currentDate, loadedEvents);
          } else {
            renderCalendar(calendar, currentDate, loadedEvents);
          }
        }

        function doLoad() {
          var url = calendar.getAttribute("data-events-url");
          var start, end;

          if (currentView === "week") {
            start = new Date(currentDate);
            start.setDate(start.getDate() - start.getDay());
            end = new Date(start);
            end.setDate(start.getDate() + 7);
          } else if (currentView === "day") {
            start = new Date(currentDate);
            end = new Date(currentDate);
            end.setDate(end.getDate() + 1);
          } else {
            start = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1);
            end = new Date(currentDate.getFullYear(), currentDate.getMonth() + 1, 1);
          }

          var params = new URLSearchParams({
            start: toDateKey(start),
            end: toDateKey(end),
          });

          setLoadingState(calendar, currentDate);

          fetch(url + "?" + params.toString(), { credentials: "same-origin" })
            .then(function (r) {
              if (!r.ok) throw new Error("Calendar unavailable");
              return r.json();
            })
            .then(function (payload) {
              loadedEvents = payload.events || [];
              renderCurrentView();
            })
            .catch(function () {
              calendar.classList.remove("is-loading");
              var grid = calendar.querySelector(".reservaplus-calendar-grid, .reservaplus-week-grid, .reservaplus-day-view");
              if (grid) grid.textContent = "Não foi possível carregar a disponibilidade. Atualize a página para tentar novamente.";
            });
        }

        doLoad();

        // Click on any day cell or day-view event row opens day detail
        calendar.addEventListener("click", function (event) {
          var dayEl = event.target.closest("[data-reservaplus-day]");
          if (dayEl) {
            openDayDetail(dayEl.getAttribute("data-reservaplus-day"), loadedEvents);
          }
        });

        calendar.addEventListener("keydown", function (event) {
          if (event.key === "Enter" || event.key === " ") {
            var dayEl = event.target.closest("[data-reservaplus-day]");
            if (dayEl) {
              event.preventDefault();
              openDayDetail(
                dayEl.getAttribute("data-reservaplus-day"),
                loadedEvents
              );
            }
          }
        });

        var panel =
          calendar.closest(".reservaplus-calendar-panel") || document;

        // View switcher
        panel.querySelectorAll("[data-reservaplus-view]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            currentView = btn.getAttribute("data-reservaplus-view") || "month";
            panel.querySelectorAll("[data-reservaplus-view]").forEach(function (b) {
              b.classList.remove("btn-secondary");
              b.classList.add("btn-outline-secondary");
            });
            btn.classList.remove("btn-outline-secondary");
            btn.classList.add("btn-secondary");
            doLoad();
          });
        });

        var prevBtn = panel.querySelector(".reservaplus-calendar-prev");
        var nextBtn = panel.querySelector(".reservaplus-calendar-next");
        var todayBtn = panel.querySelector(".reservaplus-calendar-today");
        var openTodayBtn = panel.querySelector("[data-reservaplus-open-today]");

        if (prevBtn) {
          prevBtn.addEventListener("click", function () {
            if (currentView === "week") {
              currentDate = new Date(currentDate);
              currentDate.setDate(currentDate.getDate() - 7);
            } else if (currentView === "day") {
              currentDate = new Date(currentDate);
              currentDate.setDate(currentDate.getDate() - 1);
            } else {
              currentDate = new Date(
                currentDate.getFullYear(),
                currentDate.getMonth() - 1,
                1
              );
            }
            doLoad();
          });
        }

        if (nextBtn) {
          nextBtn.addEventListener("click", function () {
            if (currentView === "week") {
              currentDate = new Date(currentDate);
              currentDate.setDate(currentDate.getDate() + 7);
            } else if (currentView === "day") {
              currentDate = new Date(currentDate);
              currentDate.setDate(currentDate.getDate() + 1);
            } else {
              currentDate = new Date(
                currentDate.getFullYear(),
                currentDate.getMonth() + 1,
                1
              );
            }
            doLoad();
          });
        }

        if (todayBtn) {
          todayBtn.addEventListener("click", function () {
            currentDate = new Date();
            doLoad();
          });
        }

        if (openTodayBtn) {
          openTodayBtn.addEventListener("click", function () {
            openQuickReservation(toDateKey(new Date()));
          });
        }

        // Ir direto para uma data, sem clicar mês a mês até chegar nela.
        var jumpInput = panel.querySelector("[data-reservaplus-date-jump]");
        var jumpBtn = panel.querySelector("[data-reservaplus-date-go]");

        function jumpToDate() {
          if (!jumpInput || !jumpInput.value) return;
          var parts = jumpInput.value.split("-");
          if (parts.length !== 3) return;
          var target = new Date(
            Number(parts[0]),
            Number(parts[1]) - 1,
            Number(parts[2])
          );
          if (isNaN(target.getTime())) return;
          currentDate = target;
          doLoad();
        }

        if (jumpBtn) {
          jumpBtn.addEventListener("click", jumpToDate);
        }
        if (jumpInput) {
          jumpInput.addEventListener("change", jumpToDate);
          jumpInput.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
              event.preventDefault();
              jumpToDate();
            }
          });
        }
      });
  }

  // --- Search panels (rooms and people) ---

  // Compara sem acento e sem caixa: "etica" precisa achar "2 Ética".
  function normalizeSearch(value) {
    var text = String(value || "").toLowerCase();
    if (text.normalize) {
      // NFD separa a letra do acento; a classe abaixo é o bloco Unicode dos
      // sinais diacríticos combinantes (U+0300–U+036F), que então somem.
      text = text.normalize("NFD").replace(new RegExp("[̀-ͯ]", "g"), "");
    }
    return text.trim();
  }

  /**
   * Filtra os cartões de sala pelo nome (e some com a categoria que esvaziou).
   *
   * Só atua onde NÃO existe o filtro de capacidade/recursos — no formulário
   * completo a busca já entra no passe de filtragem daquele bloco, que é o
   * único dono do `hidden` dos cartões.
   */
  function initItemSearch() {
    document
      .querySelectorAll("[data-reservaplus-item-search]")
      .forEach(function (input) {
        var scope = input.closest("form") || document;
        if (scope.querySelector("[data-reservaplus-room-filter]")) return;

        var list = scope.querySelector("[data-reservaplus-item-list]");
        if (!list) return;

        input.addEventListener("keydown", function (event) {
          if (event.key === "Enter") event.preventDefault();
        });

        input.addEventListener("input", function () {
          var needle = normalizeSearch(input.value);

          list.querySelectorAll(".rp-item-card").forEach(function (card) {
            var name = card.querySelector(".rp-item-name");
            var text = normalizeSearch(name ? name.textContent : "");
            card.hidden = !(needle === "" || text.indexOf(needle) !== -1);
          });

          list.querySelectorAll(".rp-item-cat").forEach(function (group) {
            var visible = Array.prototype.slice
              .call(group.querySelectorAll(".rp-item-card"))
              .some(function (card) {
                return !card.hidden;
              });
            group.hidden = !visible;
          });
        });
      });
  }

  /** Filtra as opções do <select> de pessoas. */
  function initUserSearch() {
    document
      .querySelectorAll("[data-reservaplus-user-picker]")
      .forEach(function (picker) {
        var input = picker.querySelector("[data-reservaplus-user-search]");
        var select = picker.querySelector("[data-reservaplus-user-select]");
        var empty = picker.querySelector("[data-reservaplus-user-empty]");
        if (!input || !select) return;

        var options = Array.prototype.slice.call(select.options).map(function (
          option
        ) {
          return { option: option, text: normalizeSearch(option.textContent) };
        });

        input.addEventListener("input", function () {
          var needle = normalizeSearch(input.value);
          var shown = 0;

          options.forEach(function (entry) {
            var hit = needle === "" || entry.text.indexOf(needle) !== -1;
            // hidden não é confiável em <option> no Safari; o disabled evita
            // que uma opção filtrada seja escolhida pelo teclado.
            entry.option.hidden = !hit;
            entry.option.disabled = !hit;
            if (hit) shown++;
          });

          if (empty) empty.hidden = shown > 0;
        });
      });
  }

  // --- Day detail modal init ---

  function initDayDetailModal() {
    var detail = document.querySelector("[data-reservaplus-daydetail]");
    if (!detail) return;

    detail.addEventListener("click", function (event) {
      if (event.target.closest("[data-reservaplus-daydetail-close]")) {
        closeDayDetail();
        return;
      }
      if (event.target === detail) {
        closeDayDetail();
      }
    });

    var reserveBtn = detail.querySelector("[data-reservaplus-daydetail-reserve]");
    if (reserveBtn) {
      reserveBtn.addEventListener("click", function () {
        var dateKey =
          detail.getAttribute("data-reservaplus-daydetail-datekey") ||
          toDateKey(new Date());
        closeDayDetail();
        openQuickReservation(dateKey);
      });
    }
  }

  // --- Quick reservation modal init ---

  function initQuickReservationModal() {
    document
      .querySelectorAll("[data-reservaplus-modal-close]")
      .forEach(function (button) {
        button.addEventListener("click", closeQuickReservation);
      });
  }

  // --- Real-time availability checker ---

  function setAvailabilityState(box, state, message) {
    box.className = "reservaplus-availability reservaplus-field-wide is-" + state;
    var icons = {
      checking: "ti ti-loader-2",
      available: "ti ti-circle-check",
      busy: "ti ti-alert-triangle",
      error: "ti ti-help-circle",
      info: "ti ti-info-circle",
    };
    box.innerHTML =
      "<i class='" + (icons[state] || icons.error) + "'></i><span></span>";
    box.querySelector("span").textContent = message;
    box.hidden = false;
  }

  function describeConflicts(conflicts) {
    if (!conflicts || !conflicts.length) return "";
    var parts = conflicts.map(function (c) {
      var time = "";
      if (c.begin && c.end) {
        time = formatTime(c.begin) + "–" + formatTime(c.end);
      }
      if (c.type === "block") {
        return "bloqueio" + (time ? " " + time : "");
      }
      return "reserva" + (time ? " " + time : "");
    });
    return parts.join(", ");
  }

  function initAvailabilityChecker() {
    document
      .querySelectorAll("[data-reservaplus-availability-form]")
      .forEach(function (form) {
        var url = form.getAttribute("data-availability-url");
        var box = form.querySelector("[data-reservaplus-availability]");
        var itemContainer = form.querySelector("[data-reservaplus-item-list]");
        var itemField = itemContainer || form.querySelector(
          "[name='reservationitems_id'], [name='reservationitems_id[]']"
        );
        var beginField = form.querySelector("[name='begin']");
        var endField = form.querySelector("[name='end']");
        if (!url || !box || !itemField || !beginField || !endField) return;

        var timer = null;
        var token = 0;

        function selectedItemIds() {
          if (itemContainer) {
            return Array.prototype.slice
              .call(itemContainer.querySelectorAll("input[type='checkbox']:checked"))
              .map(function (cb) { return parseInt(cb.value, 10) || 0; })
              .filter(function (v) { return v > 0; });
          }
          if (itemField.multiple) {
            return Array.prototype.slice
              .call(itemField.selectedOptions)
              .map(function (o) {
                return parseInt(o.value, 10) || 0;
              })
              .filter(function (v) {
                return v > 0;
              });
          }
          var v = parseInt(itemField.value, 10) || 0;
          return v > 0 ? [v] : [];
        }

        function check() {
          var ids = selectedItemIds();
          var begin = beginField.value;
          var end = endField.value;

          if (ids.length === 0 || !begin || !end) {
            box.hidden = true;
            return;
          }
          if (new Date(begin) >= new Date(end)) {
            setAvailabilityState(box, "busy", "O fim deve ser depois do início.");
            return;
          }
          if (ids.length > 1) {
            setAvailabilityState(
              box,
              "info",
              ids.length +
                " itens selecionados — a disponibilidade de cada um é verificada ao reservar."
            );
            return;
          }
          var itemId = ids[0];

          setAvailabilityState(box, "checking", "Verificando disponibilidade…");
          var current = ++token;
          var params = new URLSearchParams({
            reservationitems_id: String(itemId),
            begin: begin,
            end: end,
          });
          // Ao reagendar, a própria reserva não conta como conflito.
          var editingId = form.getAttribute("data-editing-request-id");
          if (editingId) params.append("editing_request_id", editingId);

          fetch(url + "?" + params.toString(), { credentials: "same-origin" })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              if (current !== token) return; // resposta obsoleta
              if (data.available) {
                setAvailabilityState(
                  box,
                  "available",
                  "Disponível neste horário."
                );
              } else {
                var desc = describeConflicts(data.conflicts);
                setAvailabilityState(
                  box,
                  "busy",
                  "Indisponível" + (desc ? " (" + desc + ")" : "") + "."
                );
              }
            })
            .catch(function () {
              if (current !== token) return;
              setAvailabilityState(
                box,
                "error",
                "Não foi possível verificar agora."
              );
            });
        }

        function schedule() {
          if (timer) window.clearTimeout(timer);
          timer = window.setTimeout(check, 350);
        }

        (itemContainer || itemField).addEventListener("change", schedule);
        beginField.addEventListener("change", schedule);
        endField.addEventListener("change", schedule);
        beginField.addEventListener("input", schedule);
        endField.addEventListener("input", schedule);

        check(); // estado inicial
      });
  }

  // --- Global keyboard handler ---

  document.addEventListener("keydown", function (event) {
    if (event.key !== "Escape") return;
    var detail = document.querySelector("[data-reservaplus-daydetail]");
    if (detail && !detail.hidden) {
      closeDayDetail();
      return;
    }
    closeQuickReservation();
  });

  // --- Atalhos de duração + seleção de itens por categoria ---

  function initReservationFormTools() {
    function pad(n) {
      return (n < 10 ? "0" : "") + n;
    }
    function fmtLocal(d) {
      return (
        d.getFullYear() +
        "-" +
        pad(d.getMonth() + 1) +
        "-" +
        pad(d.getDate()) +
        "T" +
        pad(d.getHours()) +
        ":" +
        pad(d.getMinutes())
      );
    }
    function parseHM(value, fallbackH, fallbackM) {
      var m = /^(\d{1,2}):(\d{2})/.exec(value || "");
      return m ? [parseInt(m[1], 10), parseInt(m[2], 10)] : [fallbackH, fallbackM];
    }

    // Atalhos de duração
    document
      .querySelectorAll("[data-reservaplus-presets]")
      .forEach(function (bar) {
        var form = bar.closest("form");
        if (!form) return;
        var beginField = form.querySelector("[name='begin']");
        var endField = form.querySelector("[name='end']");
        if (!beginField || !endField) return;

        var bhStart = parseHM(bar.getAttribute("data-bh-start"), 8, 0);
        var bhEnd = parseHM(bar.getAttribute("data-bh-end"), 18, 0);

        function baseDate() {
          var v = beginField.value ? new Date(beginField.value) : null;
          if (v && !isNaN(v.getTime())) return v;
          var n = new Date();
          n.setMinutes(0, 0, 0);
          n.setHours(n.getHours() + 1);
          return n;
        }
        function setRange(begin, end) {
          beginField.value = fmtLocal(begin);
          endField.value = fmtLocal(end);
          beginField.dispatchEvent(new Event("change", { bubbles: true }));
          endField.dispatchEvent(new Event("change", { bubbles: true }));
        }

        bar.querySelectorAll("button[data-duration]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            var mins = parseInt(btn.getAttribute("data-duration"), 10) || 60;
            var b = baseDate();
            setRange(b, new Date(b.getTime() + mins * 60000));
          });
        });
        bar.querySelectorAll("button[data-period]").forEach(function (btn) {
          btn.addEventListener("click", function () {
            var b = baseDate();
            var e = new Date(b.getTime());
            var period = btn.getAttribute("data-period");
            if (period === "morning") {
              b.setHours(8, 0, 0, 0);
              e.setHours(12, 0, 0, 0);
            } else if (period === "afternoon") {
              b.setHours(13, 0, 0, 0);
              e.setHours(18, 0, 0, 0);
            } else {
              b.setHours(bhStart[0], bhStart[1], 0, 0);
              e.setHours(bhEnd[0], bhEnd[1], 0, 0);
            }
            setRange(b, e);
          });
        });
      });

    // Seleção de itens por categoria
    document
      .querySelectorAll("[data-reservaplus-item-tools]")
      .forEach(function (tools) {
        var form = tools.closest("form");
        if (!form) return;
        var itemContainer = form.querySelector("[data-reservaplus-item-list]");
        var select = !itemContainer ? form.querySelector("select[name='reservationitems_id[]']") : null;
        if (!itemContainer && !select) return;
        var catSelect = tools.querySelector("[data-reservaplus-cat]");

        function fireChange() {
          (itemContainer || select).dispatchEvent(new Event("change", { bubbles: true }));
        }
        function setAll(value) {
          if (itemContainer) {
            itemContainer.querySelectorAll("input[type='checkbox']").forEach(function (cb) {
              cb.checked = value;
            });
          } else {
            Array.prototype.forEach.call(select.options, function (o) {
              o.selected = value;
            });
          }
          fireChange();
        }

        var selCatBtn = tools.querySelector("[data-reservaplus-select-cat]");
        if (selCatBtn && catSelect) {
          selCatBtn.addEventListener("click", function () {
            var cat = catSelect.value;
            if (!cat) return;
            if (itemContainer) {
              var group = itemContainer.querySelector("[data-group='" + CSS.escape(cat) + "']");
              if (group) group.querySelectorAll("input[type='checkbox']").forEach(function (cb) { cb.checked = true; });
            } else {
              Array.prototype.forEach.call(select.options, function (o) {
                var g = o.parentElement && o.parentElement.tagName === "OPTGROUP" ? o.parentElement.label : "";
                if (g === cat) o.selected = true;
              });
            }
            fireChange();
          });
        }
        var allBtn = tools.querySelector("[data-reservaplus-select-all]");
        if (allBtn)
          allBtn.addEventListener("click", function () {
            setAll(true);
          });
        var clearBtn = tools.querySelector("[data-reservaplus-clear-sel]");
        if (clearBtn)
          clearBtn.addEventListener("click", function () {
            setAll(false);
          });
      });

    // Buscar horários livres do dia (janelas em que o(s) item(ns) estão livres)
    document
      .querySelectorAll("[data-reservaplus-find-slots]")
      .forEach(function (btn) {
        var form = btn.closest("form");
        if (!form) return;
        var url = form.getAttribute("data-slots-url");
        var box = form.querySelector("[data-reservaplus-slots]");
        var beginField = form.querySelector("[name='begin']");
        var endField = form.querySelector("[name='end']");
        var itemContainer = form.querySelector("[data-reservaplus-item-list]");
        var select = !itemContainer ? form.querySelector("select[name='reservationitems_id[]']") : null;
        if (!url || !box || !beginField || !endField || (!itemContainer && !select)) return;

        function selectedIds() {
          if (itemContainer) {
            return Array.prototype.slice
              .call(itemContainer.querySelectorAll("input[type='checkbox']:checked"))
              .map(function (cb) { return parseInt(cb.value, 10) || 0; })
              .filter(function (v) { return v > 0; });
          }
          return Array.prototype.slice
            .call(select.selectedOptions)
            .map(function (o) {
              return parseInt(o.value, 10) || 0;
            })
            .filter(function (v) {
              return v > 0;
            });
        }
        function currentDurationMin() {
          var b = beginField.value ? new Date(beginField.value) : null;
          var e = endField.value ? new Date(endField.value) : null;
          if (b && e && !isNaN(b.getTime()) && !isNaN(e.getTime()) && e > b) {
            return Math.round((e.getTime() - b.getTime()) / 60000);
          }
          return 60;
        }

        btn.addEventListener("click", function () {
          var ids = selectedIds();
          box.hidden = false;
          if (ids.length === 0) {
            box.innerHTML =
              "<span class='reservaplus-slots-empty'>Selecione ao menos um item.</span>";
            return;
          }
          var date = beginField.value
            ? beginField.value.slice(0, 10)
            : new Date().toISOString().slice(0, 10);
          var params = new URLSearchParams();
          ids.forEach(function (id) {
            params.append("reservationitems_id[]", String(id));
          });
          params.append("date", date);

          box.innerHTML =
            "<span class='reservaplus-slots-loading'>Buscando horários livres…</span>";
          fetch(url + "?" + params.toString(), { credentials: "same-origin" })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              var slots = (data && data.slots) || [];
              if (slots.length === 0) {
                box.innerHTML =
                  "<span class='reservaplus-slots-empty'>Nenhuma janela livre nesse dia.</span>";
                return;
              }
              box.innerHTML = "";
              var dur = currentDurationMin();
              slots.forEach(function (s) {
                var chip = document.createElement("button");
                chip.type = "button";
                chip.className =
                  "btn btn-sm btn-outline-success reservaplus-slot-chip";
                chip.textContent = s.label;
                chip.addEventListener("click", function () {
                  var start = new Date(s.begin_local);
                  var endWin = new Date(s.end_local);
                  var end = new Date(start.getTime() + dur * 60000);
                  if (end > endWin) end = endWin;
                  beginField.value = s.begin_local;
                  endField.value = fmtLocal(end);
                  beginField.dispatchEvent(new Event("change", { bubbles: true }));
                  endField.dispatchEvent(new Event("change", { bubbles: true }));
                });
                box.appendChild(chip);
              });
            })
            .catch(function () {
              box.innerHTML =
                "<span class='reservaplus-slots-empty'>Não foi possível buscar agora.</span>";
            });
        });
      });
    // Seleção única (tela de edição): marcar uma sala desmarca as outras.
    document
      .querySelectorAll("[data-reservaplus-item-list][data-reservaplus-single]")
      .forEach(function (list) {
        list.addEventListener("change", function (e) {
          var target = e.target;
          if (!target || target.type !== "checkbox" || !target.checked) return;
          list
            .querySelectorAll("input[type='checkbox']")
            .forEach(function (cb) {
              if (cb !== target) cb.checked = false;
            });
        });
      });

    // Busca de sala: capacidade (segue a quantidade de pessoas) + recursos.
    document
      .querySelectorAll("[data-reservaplus-room-filter]")
      .forEach(function (filter) {
        var form = filter.closest("form");
        if (!form) return;
        var list = form.querySelector("[data-reservaplus-item-list]");
        var peopleField = form.querySelector("[name='people_count']");
        var fitToggle = filter.querySelector("[data-reservaplus-fit-filter]");
        var emptyMsg = filter.querySelector("[data-reservaplus-filter-empty]");
        var counter = form.querySelector("[data-reservaplus-item-count]");
        // A busca por nome entra no MESMO passe de filtragem que capacidade e
        // recursos: com dois donos do `hidden` um sobrescreveria o outro, e o
        // contador de salas mostraria um número que não bate com a tela.
        var searchField = form.querySelector("[data-reservaplus-item-search]");
        if (!list) return;

        var featureBoxes = Array.prototype.slice.call(
          filter.querySelectorAll("[data-reservaplus-feature]")
        );

        function wantedFeatures() {
          return featureBoxes
            .filter(function (cb) {
              return cb.checked;
            })
            .map(function (cb) {
              return cb.value;
            });
        }

        function apply() {
          var people = parseInt(peopleField ? peopleField.value : "", 10) || 0;
          var byFit = !!(fitToggle && fitToggle.checked) && people > 0;
          var wanted = wantedFeatures();
          var needle = normalizeSearch(searchField ? searchField.value : "");
          var visible = 0;

          list.querySelectorAll(".rp-item-card").forEach(function (card) {
            var capacity = parseInt(card.getAttribute("data-capacity"), 10) || 0;
            var features = (card.getAttribute("data-features") || "")
              .split(",")
              .filter(Boolean);
            var nameEl = card.querySelector(".rp-item-name");
            var name = normalizeSearch(nameEl ? nameEl.textContent : "");

            // Sala sem capacidade definida nunca é escondida pelo filtro de
            // lotação — "não informado" não é o mesmo que "não cabe".
            var fits = !byFit || capacity === 0 || capacity >= people;
            var hasFeatures = wanted.every(function (f) {
              return features.indexOf(f) !== -1;
            });
            var matches = needle === "" || name.indexOf(needle) !== -1;
            var show = fits && hasFeatures && matches;

            card.hidden = !show;
            if (show) {
              visible++;
            } else {
              // Não deixa submeter uma sala que o usuário não está mais vendo.
              var cb = card.querySelector("input[type='checkbox']");
              if (cb) cb.checked = false;
            }
          });

          // Esconde categorias que ficaram sem nenhuma sala visível.
          list.querySelectorAll(".rp-item-cat").forEach(function (cat) {
            var anyVisible = Array.prototype.slice
              .call(cat.querySelectorAll(".rp-item-card"))
              .some(function (card) {
                return !card.hidden;
              });
            cat.hidden = !anyVisible;
          });

          if (emptyMsg) emptyMsg.hidden = visible > 0;
          if (counter) {
            counter.textContent =
              visible === 1 ? "1 sala" : visible + " salas";
          }
          list.dispatchEvent(new Event("change", { bubbles: true }));
        }

        if (fitToggle) fitToggle.addEventListener("change", apply);
        featureBoxes.forEach(function (cb) {
          cb.addEventListener("change", function () {
            cb.closest(".rp-feature-chip").classList.toggle(
              "is-checked",
              cb.checked
            );
            apply();
          });
        });
        if (peopleField) {
          peopleField.addEventListener("input", apply);
          peopleField.addEventListener("change", apply);
        }
        if (searchField) {
          searchField.addEventListener("input", apply);
          // Enter num campo de busca não deve enviar a reserva.
          searchField.addEventListener("keydown", function (event) {
            if (event.key === "Enter") event.preventDefault();
          });
        }

        apply();
      });

    // Recorrência: mostra/oculta campos conforme o tipo escolhido
    document
      .querySelectorAll("[data-reservaplus-recurrence]")
      .forEach(function (rec) {
        var form = rec.closest("form");
        var typeSel = rec.querySelector("[data-reservaplus-rec-type]");
        var weekdays = rec.querySelector("[data-reservaplus-rec-weekdays]");
        var endBox = rec.querySelector("[data-reservaplus-rec-end]");
        var endMode = rec.querySelector("[data-reservaplus-rec-endmode]");
        var countBox = rec.querySelector("[data-reservaplus-rec-count]");
        var untilBox = rec.querySelector("[data-reservaplus-rec-until]");
        var beginField = form ? form.querySelector("[name='begin']") : null;
        if (!typeSel) return;

        function syncType() {
          var t = typeSel.value;
          if (endBox) endBox.hidden = t === "none";
          if (weekdays) {
            weekdays.hidden = t !== "weekly";
            if (
              t === "weekly" &&
              beginField &&
              beginField.value &&
              !weekdays.querySelector("input:checked")
            ) {
              var dow = new Date(beginField.value).getDay();
              var cb = weekdays.querySelector("input[value='" + dow + "']");
              if (cb) cb.checked = true;
            }
          }
        }
        function syncEndMode() {
          if (!endMode) return;
          var isCount = endMode.value === "count";
          if (countBox) countBox.hidden = !isCount;
          if (untilBox) untilBox.hidden = isCount;
        }
        typeSel.addEventListener("change", syncType);
        if (endMode) endMode.addEventListener("change", syncEndMode);
        syncType();
        syncEndMode();
      });
  }

  // --- Animação de submit e validação de itens ---

  function initReservationSubmit() {
    document.querySelectorAll("[data-reservaplus-availability-form]").forEach(function (container) {
      var theForm = container.closest("form");
      if (!theForm) return;
      theForm.addEventListener("submit", function (e) {
        var itemList = container.querySelector("[data-reservaplus-item-list]");
        if (itemList) {
          var checked = itemList.querySelectorAll("input[type='checkbox']:checked");
          if (checked.length === 0) {
            e.preventDefault();
            itemList.classList.add("rp-item-list--error");
            setTimeout(function () { itemList.classList.remove("rp-item-list--error"); }, 1500);
            return;
          }
        }

        // Mostra overlay e desabilita botão — sem preventDefault para não quebrar CSRF.
        // O disable precisa ser adiado (setTimeout): botão desabilitado no momento
        // do submit fica FORA dos dados do POST e o backend nunca recebe name='add'.
        var btn = theForm.querySelector("[name='add'], [name='update']");
        var label =
          btn && btn.name === "update"
            ? "Salvando alterações…"
            : "Criando reserva…";
        if (btn) {
          setTimeout(function () {
            btn.disabled = true;
            btn.innerHTML = "<span class='rp-btn-spinner'></span> " + label;
          }, 0);
        }
        var overlay = document.createElement("div");
        overlay.className = "rp-submit-overlay";
        overlay.innerHTML =
          "<div class='rp-submit-card'>" +
          "<div class='rp-submit-spinner'></div>" +
          "<span></span>" +
          "</div>";
        overlay.querySelector("span").textContent = label;
        document.body.appendChild(overlay);
      });
    });
  }

  // --- Bootstrap ---

  function initAll() {
    initCalendars();
    initDayDetailModal();
    initQuickReservationModal();
    initAvailabilityChecker();
    initItemSearch();
    initUserSearch();
    initReservationFormTools();
    initReservationSubmit();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }
})();
