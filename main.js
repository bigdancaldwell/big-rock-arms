// Big Rock Arms: the request ticket prints itself onto the carton face as you type,
// then sends it to the shop through send.php (falling back to the visitor's email app).
(function () {
  var TO = 'bigrockarms@gmail.com';
  var form = document.getElementById('request-form');
  if (!form) return;

  // by id: form.elements.item is the collection's own item() method, not the field
  var item = document.getElementById('item');
  var name = document.getElementById('name');
  var email = document.getElementById('email');
  var budget = document.getElementById('budget');
  var out = {
    contents: document.getElementById('band-contents'),
    specContents: document.getElementById('spec-contents'),
    packed: document.getElementById('packed'),
    kind: document.getElementById('kind')
  };
  var defaults = {
    contents: out.contents.textContent,
    packed: out.packed.textContent
  };

  function clip(s, n) {
    s = s.replace(/\s+/g, ' ').trim();
    return s.length > n ? s.slice(0, n - 1).trim() + '…' : s;
  }
  function print(el, value, fallback) {
    el.textContent = value || fallback;
    el.classList.toggle('is-filled', !!value);
  }
  function kindValue() {
    var k = form.querySelector('input[name=kind]:checked');
    return k ? k.value : 'Special order';
  }

  // A single FFL transfer is a flat $20: choosing it fills the budget box with the fee.
  var TRANSFER = 'FFL transfer';
  var TRANSFER_FEE = '$20';
  var budgetLabel = document.querySelector('label[for=budget]');
  var budgetLabelHTML = budgetLabel.innerHTML;
  var itemPlaceholder = item.placeholder;
  var lastKind = kindValue();

  function applyKind(kind) {
    if (kind === lastKind) return;
    if (kind === TRANSFER) {
      budget.dataset.prev = budget.value;
      budget.value = TRANSFER_FEE;
      budget.readOnly = true;
      budgetLabel.innerHTML = 'Transfer fee <span class="opt">(single firearm)</span>';
      item.placeholder = 'The firearm you bought, and who is shipping it';
    } else if (lastKind === TRANSFER) {
      budget.value = budget.dataset.prev || '';
      budget.readOnly = false;
      budgetLabel.innerHTML = budgetLabelHTML;
      item.placeholder = itemPlaceholder;
    }
    lastKind = kind;
  }

  function sync() {
    applyKind(kindValue());
    print(out.contents, clip(item.value, 70), defaults.contents);
    print(out.specContents, clip(item.value, 70), defaults.contents);
    print(out.packed, clip(name.value, 40), defaults.packed);
    out.kind.textContent = kindValue();
  }

  form.addEventListener('input', sync);
  form.addEventListener('change', sync);

  function setError(input, msgId, bad) {
    var msg = document.getElementById(msgId);
    input.setAttribute('aria-invalid', bad ? 'true' : 'false');
    msg.hidden = !bad;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var itemBad = !item.value.trim();
    var emailBad = !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
    setError(item, 'item-err', itemBad);
    setError(email, 'email-err', emailBad);
    if (itemBad) { item.focus(); return; }
    if (emailBad) { email.focus(); return; }

    var kind = kindValue();
    var subject = kind + ': ' + clip(item.value, 60);
    var lines = [
      'Hi Big Rock Arms,',
      '',
      'Order type: ' + kind,
      'Looking for: ' + item.value.trim(),
      (kind === TRANSFER
        ? 'Transfer fee: ' + TRANSFER_FEE + ' (single firearm)'
        : 'Budget: ' + (budget.value.trim() || 'Not specified')),
      '',
      'Name: ' + (name.value.trim() || ''),
      'Email: ' + email.value.trim(),
      '',
      'Thanks!'
    ];
    var note = document.getElementById('form-note');
    var button = form.querySelector('button[type=submit]');

    // if the site can't send it, fall back to the visitor's email app so the request isn't lost
    function viaEmailApp() {
      window.location.href = 'mailto:' + TO +
        '?subject=' + encodeURIComponent(subject) +
        '&body=' + encodeURIComponent(lines.join('\n'));
      note.innerHTML = 'We couldn&rsquo;t send that from the site, so your email app should open with the request ready. ' +
        'If nothing happened, email <a href="mailto:' + TO + '">' + TO + '</a> directly.';
    }
    function ready() {
      button.disabled = false;
      button.textContent = 'Send request';
    }

    var data = new FormData(form);
    data.set('kind', kind);
    button.disabled = true;
    button.textContent = 'Sending…';

    fetch('send.php', { method: 'POST', body: data })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
      .then(function (res) {
        if (res.body.ok) {
          form.classList.add('is-sent');
          button.textContent = 'Request sent';
          note.textContent = 'Got it. We’ll reply to ' + email.value.trim() + ' with availability and a price.';
        } else if (res.status === 422) {
          ready();
          note.textContent = res.body.message;
        } else {
          ready();
          viaEmailApp();
        }
      })
      .catch(function () {
        ready();
        viaEmailApp();
      });
  });

  ['blur'].forEach(function (ev) {
    email.addEventListener(ev, function () {
      if (email.getAttribute('aria-invalid') === 'true') {
        setError(email, 'email-err', !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim()));
      }
    });
    item.addEventListener(ev, function () {
      if (item.getAttribute('aria-invalid') === 'true') setError(item, 'item-err', !item.value.trim());
    });
  });

  var y = document.getElementById('year');
  if (y) y.textContent = new Date().getFullYear();
})();
