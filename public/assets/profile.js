'use strict';

/**
 * TOTP enrollment controller for the profile page.
 *
 * QRCode.js is bundled locally. Enrollment secrets remain in the browser only
 * for the active setup response and are never written to console output.
 */
(() => {
  // Ergänzt bei schreibenden Anfragen automatisch das sitzungsgebundene CSRF-Token.
  const originalFetch = window.fetch.bind(window);
  window.fetch = (input, options = {}) => {
    const method = String(options.method || 'GET').toUpperCase();
    if (!['GET', 'HEAD'].includes(method)) {
      const headers = new Headers(options.headers || {});
      headers.set('X-CSRF-Token', document.body.dataset.csrf || '');
      options = {...options, headers};
    }
    return originalFetch(input, options);
  };

  // Hält die wiederverwendeten DOM-Elemente der 2FA-Oberfläche bereit.
  const status = document.getElementById('twoFactorStatus');
  const setup = document.getElementById('twoFactorSetup');
  const actions = document.getElementById('twoFactorActions');
  const secret = document.getElementById('twoFactorSecret');
  const uri = document.getElementById('twoFactorUri');
  const qr = document.getElementById('twoFactorQr');
  const code = document.getElementById('twoFactorCode');
  /** Maskiert dynamische Werte vor einer möglichen HTML-Ausgabe. */
  const escapeHtml = value => String(value ?? '').replace(
    /[&<>'"]/g,
    character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[character])
  );

  /** Updates status text and exposes only the action valid for the current state. */
  function render(enabled) {
    status.innerHTML = enabled ? '<span class="badge badge-success">AKTIV</span><div><strong>2FA ist aktiv.</strong><p class="muted">Bei der nächsten Anmeldung wird zusätzlich ein TOTP-Code verlangt.</p></div>' : '<span class="badge badge-muted">AUS</span><div><strong>2FA ist nicht aktiviert.</strong><p class="muted">Aktiviere 2FA, um dein Konto zusätzlich zu schützen.</p></div>';
    actions.innerHTML = enabled ? '<button id="disable2faBtn" class="btn btn-danger">2FA deaktivieren</button>' : '<button id="start2faBtn" class="btn btn-primary">2FA einrichten</button>';
    document.getElementById('start2faBtn')?.addEventListener('click', begin);
    document.getElementById('disable2faBtn')?.addEventListener('click', disable);
  }

  /** Retrieves the current enrollment state without returning the stored secret. */
  async function load() {
    try {
      const response = await fetch('api/2fa.php');
      const data = await response.json();
      render(Boolean(Number(data.enabled)));
    } catch (error) {
      status.innerHTML = '<div class="notice error">2FA-Status konnte nicht geladen werden.</div>';
    }
  }

  /** Starts enrollment and renders the one-time provisioning URI as a QR code. */
  async function begin() {
    const formData = new FormData();
    formData.append('action', 'begin');
    const response = await fetch('api/2fa.php', {method: 'POST', body: formData});
    const data = await response.json();
    if (!response.ok) {
      alert(data.error || 'Fehler');
      return;
    }

    // Das Geheimnis wird nur für den aktuellen Einrichtungsvorgang angezeigt.
    setup.classList.remove('hidden');
    secret.textContent = data.secret;
    uri.value = data.uri;
    qr.innerHTML = '';
    if (window.QRCode) {
      new QRCode(qr, {
        text: data.uri,
        width: 180,
        height: 180,
        correctLevel: QRCode.CorrectLevel.M
      });
    }
    code.focus();
  }

  // Bricht die noch nicht bestätigte Einrichtung ohne Serveränderung ab.
  document.getElementById('cancel2faBtn').addEventListener('click', () => {
    setup.classList.add('hidden');
  });

  // Bestätigt das neue TOTP-Geheimnis mit dem eingegebenen Einmalcode.
  document.getElementById('confirm2faBtn').addEventListener('click', async () => {
    const formData = new FormData();
    formData.append('action', 'enable');
    formData.append('code', code.value.trim());
    const response = await fetch('api/2fa.php', {method: 'POST', body: formData});
    const data = await response.json();
    if (!response.ok) {
      alert(data.error || 'Code ungültig');
      return;
    }
    setup.classList.add('hidden');
    await load();
  });

  /** Disables TOTP only after the server verifies the current password. */
  async function disable() {
    const password = prompt('Bitte dein aktuelles Passwort eingeben, um 2FA zu deaktivieren:');
    if (password === null) return;

    const formData = new FormData();
    formData.append('action', 'disable');
    formData.append('password', password);
    const response = await fetch('api/2fa.php', {method: 'POST', body: formData});
    const data = await response.json();
    if (!response.ok) {
      alert(data.error || 'Fehler');
      return;
    }
    await load();
  }

  // Lädt den Ausgangszustand, sobald das Modul ausgeführt wird.
  load();
})();
