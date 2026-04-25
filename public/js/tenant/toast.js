(function () {
  'use strict';

  var ICONS = {
    success: '<svg viewBox="0 0 10 10" fill="none"><path d="M2 5l2 2 4-4" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    error:   '<svg viewBox="0 0 10 10" fill="none"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="white" stroke-width="1.6" stroke-linecap="round"/></svg>',
    warning: '<svg viewBox="0 0 10 10" fill="none"><path d="M5 3v3M5 7.5v.01" stroke="white" stroke-width="1.6" stroke-linecap="round"/></svg>',
    info:    '<svg viewBox="0 0 10 10" fill="none"><path d="M5 3v.01M5 4.5v3" stroke="white" stroke-width="1.6" stroke-linecap="round"/></svg>'
  };

  function getStack() {
    var stack = document.getElementById('ia-toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'ia-toast-stack';
      stack.className = 'ia-toast-stack';
      document.body.appendChild(stack);
    }
    return stack;
  }

  function show(message, type, opts) {
    type = type || 'success';
    opts = opts || {};
    var duration = typeof opts.duration === 'number' ? opts.duration : 2500;

    var stack = getStack();
    var toast = document.createElement('div');
    toast.className = 'ia-toast ia-toast--' + type;
    toast.innerHTML =
      '<div class="ia-toast-icon">' + (ICONS[type] || ICONS.success) + '</div>' +
      '<span></span>';
    toast.querySelector('span').textContent = message;

    stack.appendChild(toast);
    // Force reflow so the transition fires
    void toast.offsetWidth;
    toast.classList.add('is-shown');

    var dismissed = false;
    function dismiss() {
      if (dismissed) return;
      dismissed = true;
      toast.classList.remove('is-shown');
      setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 220);
    }

    toast.addEventListener('click', dismiss);
    if (duration > 0) setTimeout(dismiss, duration);

    return { dismiss: dismiss };
  }

  window.IntakeToast = {
    show:    show,
    success: function (m, o) { return show(m, 'success', o); },
    error:   function (m, o) { return show(m, 'error',   o); },
    warning: function (m, o) { return show(m, 'warning', o); },
    info:    function (m, o) { return show(m, 'info',    o); }
  };
}());
