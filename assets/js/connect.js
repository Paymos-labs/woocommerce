(function () {
  'use strict';

  var config = window.PaymosWooConnect;
  if (!config) return;

  // Set when the browser refused the approval tab and the merchant has to open it
  // themselves. While set, polling must not overwrite the status line: the link and
  // the user code shown there are the only remaining way to finish the connection.
  var awaitingManualApproval = false;

  function post(action) {
    var body = new URLSearchParams({ action: action, nonce: config.nonce });
    return fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString()
    }).then(function (response) { return response.json(); });
  }

  function statusNode() {
    return document.getElementById('paymos-connect-status');
  }

  function status(message, failed, force) {
    if (awaitingManualApproval && !force) return;
    var node = statusNode();
    if (!node) return;
    node.textContent = message;
    node.style.color = failed ? '#b91c1c' : '#374151';
  }

  // Renders the recovery path as real markup so the merchant can still reach the
  // approval page after the browser blocked the tab.
  function manualApproval(url, userCode) {
    var node = statusNode();
    if (!node) return;
    awaitingManualApproval = true;
    node.textContent = '';
    node.style.color = '#b91c1c';

    var reason = document.createTextNode(config.messages.blocked + ' ');
    var link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = config.messages.openApproval;
    var code = document.createTextNode(' ' + config.messages.code + ' ' + userCode);

    node.appendChild(reason);
    node.appendChild(link);
    node.appendChild(code);
  }

  function poll(interval) {
    window.setTimeout(function check() {
      post(config.pollAction).then(function (response) {
        if (!response.success) {
          status((response.data && response.data.message) || config.messages.failed, true);
          return;
        }
        if (response.data.status === 'connected') {
          awaitingManualApproval = false;
          status(config.messages.connected, false, true);
          window.setTimeout(function () { window.location.reload(); }, 700);
          return;
        }
        status(config.messages.waiting, false);
        window.setTimeout(check, response.data.status === 'slow_down' ? interval + 5000 : interval);
      }).catch(function () { status(config.messages.failed, true); });
    }, interval);
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('#paymos-connect-button');
    if (!button) return;
    event.preventDefault();
    button.disabled = true;
    awaitingManualApproval = false;
    status(config.messages.starting, false, true);

    // Open the tab now, while the click is still a trusted user activation. Browsers
    // only honour window.open for a few seconds after the gesture, so opening it in
    // the start-request callback is blocked whenever that request is slow. The tab
    // starts blank and is navigated once the verification URL arrives. No feature
    // string is passed: any feature string asks for a popup window, which blockers
    // reject far more often than a plain tab.
    var tab = window.open('', '_blank');
    if (tab) {
      try { tab.opener = null; } catch (error) { /* cross-origin hardening only */ }
    }

    post(config.startAction).then(function (response) {
      if (!response.success) throw new Error((response.data && response.data.message) || config.messages.failed);
      if (tab && !tab.closed) {
        tab.location = response.data.verification_url;
        status(config.messages.waiting + ' ' + config.messages.code + ' ' + response.data.user_code, false, true);
      } else {
        manualApproval(response.data.verification_url, response.data.user_code);
      }
      poll(Math.max(1, Number(response.data.interval || 5)) * 1000);
    }).catch(function (error) {
      if (tab && !tab.closed) tab.close();
      status(error.message || config.messages.failed, true, true);
      button.disabled = false;
    });
  });
})();
