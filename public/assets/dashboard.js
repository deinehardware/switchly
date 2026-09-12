'use strict';

/**
 * Switch detail controller.
 *
 * Owns cached/live switch reads, port rendering, Chart.js integration and all
 * device-scoped write actions. The server remains authoritative for permission
 * checks, validation and CSRF protection.
 */

// Attach the session CSRF token to every same-origin mutating request.
(() => {
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
})();

/** Lazily loads the locally bundled Chart.js distribution exactly once. */
window.loadChartLibrary = window.loadChartLibrary || (() => {
    let promise = null;
    return () => {
        if (window.Chart) return Promise.resolve(window.Chart);
        if (promise) return promise;
        promise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'assets/vendor/chart.js/chart.umd.min.js?v=4.5.0';
            script.async = true;
            script.onload = () => resolve(window.Chart);
            script.onerror = () => reject(new Error('Diagramm-Bibliothek konnte nicht geladen werden.'));
            document.head.appendChild(script);
        });
        return promise;
    };
})();

/** Coordinates the switch sidebar, detail view and configuration dialogs. */
class SwitchlyDashboard {
    /** Initialisiert Zustände, DOM-Verweise und die ersten Datenabrufe. */
    constructor() {
        this.selectedSwitch = null;
        this.switches = [];
        this.currentData = null;
        this.chart = null;
        this.autoRefreshMs = 15000;
        this.autoRefreshTimer = null;
        this.refreshInProgress = false;
        this.switchConfig = null;
        this.configLoadedFor = null;
        try { this.permissions = new Set(JSON.parse(document.body.dataset.permissions || '[]')); } catch (_) { this.permissions = new Set(); }

        this.initElements();
        this.bindEvents();
        window.dashboard = this;
        this.start();
    }

    /** Caches DOM elements that are reused during periodic refreshes. */
    initElements() {
        this.el = {
            switchList: document.getElementById('switchList'),
            headerSwitchName: document.getElementById('headerSwitchName'),
            headerSwitchInfo: document.getElementById('headerSwitchInfo'),
            cacheBadge: document.getElementById('cacheBadge'),
            modelValue: document.getElementById('modelValue'),
            swosValue: document.getElementById('swosValue'),
            uptimeValue: document.getElementById('uptimeValue'),
            temperatureValue: document.getElementById('temperatureValue'),
            voltageValue: document.getElementById('voltageValue'),
            portSummary: document.getElementById('portSummary'),
            portSub: document.getElementById('portSub'),
            portsBody: document.getElementById('portsBody'),
            portSearch: document.getElementById('portSearch'),
            visualPortsContainer: document.getElementById('visualPortsContainer'),
            lastUpdate: document.getElementById('lastUpdate'),
            refreshBtn: document.getElementById('refreshButton'),
            deleteSwitchBtn: document.getElementById('deleteSwitchBtn'),
            backupSwitchBtn: document.getElementById('backupSwitchBtn'),
            restoreSwitchBtn: document.getElementById('restoreSwitchBtn'),
            rebootSwitchBtn: document.getElementById('rebootSwitchBtn'),
            restoreModal: document.getElementById('restoreModal'),
            restoreForm: document.getElementById('restoreForm'),
            addSwitchBtn: document.getElementById('addSwitchBtn'),
            addModal: document.getElementById('addModal'),
            closeAddModal: document.getElementById('closeAddModal'),
            addSwitchForm: document.getElementById('addSwitchForm'),
            showJsonBtn: document.getElementById('showJsonBtn'),
            jsonModal: document.getElementById('jsonModal'),
            closeJsonModal: document.getElementById('closeJsonModal'),
            jsonViewer: document.getElementById('jsonViewer'),
            chartCanvas: document.getElementById('trafficChart'),
            lagBody: document.getElementById('lagBody'),
            lagError: document.getElementById('lagError'),
            vlanPortBody: document.getElementById('vlanPortBody'),
            vlanBody: document.getElementById('vlanBody'),
            vlanError: document.getElementById('vlanError'),
            vlanModal: document.getElementById('vlanModal'),
            vlanForm: document.getElementById('vlanForm')
        };
    }

    /** Registers user interactions without performing network requests yet. */
    bindEvents() {
        // Öffnet den Dialog zum Hinzufügen eines Switches.
        if (this.el.addSwitchBtn) {
            this.el.addSwitchBtn.addEventListener('click', (e) => {
                e.preventDefault();
                if (this.el.addModal) this.el.addModal.classList.add('active');
            });
        }

        // Schließt den Hinzufügen-Dialog ohne Änderungen.
        if (this.el.closeAddModal) {
            this.el.closeAddModal.addEventListener('click', () => {
                if (this.el.addModal) this.el.addModal.classList.remove('active');
            });
        }

        // Übermittelt die Zugangsdaten des neuen Switches an die interne API.
        if (this.el.addSwitchForm) {
            this.el.addSwitchForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(this.el.addSwitchForm);
                formData.append('action', 'add');

                try {
                    const res = await fetch('api/manage_switches.php', { method: 'POST', body: formData });
                    const result = await res.json();
                    
                    if (result.success) {
                        if (this.el.addModal) this.el.addModal.classList.remove('active');
                        this.el.addSwitchForm.reset();
                        await this.loadOverview();
                    } else {
                        alert(result.error || 'Fehler beim Speichern.');
                    }
                } catch (err) {
                    alert('Fehler beim Senden der Daten.');
                }
            });
        }

        // Fordert die Detaildaten des ausgewählten Switches manuell neu an.
        if (this.el.refreshBtn) {
            this.el.refreshBtn.addEventListener('click', () => this.loadSwitchData(true));
        }

        // Löscht einen Switch erst nach ausdrücklicher Bestätigung.
        if (this.el.deleteSwitchBtn) {
            this.el.deleteSwitchBtn.addEventListener('click', async () => {
                if (!this.selectedSwitch || !confirm('Diesen Switch wirklich löschen?')) return;
                
                const formData = new FormData();
                formData.append('action', 'delete');
                formData.append('id', this.selectedSwitch);

                await fetch('api/manage_switches.php', { method: 'POST', body: formData });
                this.selectedSwitch = null;
                await this.loadOverview();
            });
        }

        // Verbindet Backup, Neustart und Wiederherstellung mit ihren Geräteaktionen.
        this.el.backupSwitchBtn?.addEventListener('click', () => {
            if (!this.selectedSwitch) return;
            window.location.href = `api/switch_actions.php?action=backup&id=${encodeURIComponent(this.selectedSwitch)}`;
        });
        this.el.rebootSwitchBtn?.addEventListener('click', async () => {
            if (!this.selectedSwitch || !confirm('Switch wirklich neu starten? Die Netzwerkverbindung wird kurz unterbrochen.')) return;
            await this.postAction('reboot');
            alert('Neustart wurde ausgelöst.');
        });
        this.el.restoreSwitchBtn?.addEventListener('click', () => this.el.restoreModal?.classList.add('active'));
        this.el.restoreForm?.addEventListener('submit', async e => {
            e.preventDefault();
            if (!this.selectedSwitch || !confirm('Backup wirklich wiederherstellen? Die Switch-Konfiguration wird überschrieben.')) return;
            const data = new FormData(e.target);
            data.append('action', 'restore'); data.append('id', this.selectedSwitch);
            const button = e.submitter; if (button) button.disabled = true;
            try {
                const response = await fetch('api/switch_actions.php', {method: 'POST', body: data});
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.error || 'Restore fehlgeschlagen.');
                this.el.restoreModal.classList.remove('active'); e.target.reset(); alert('Konfiguration wurde wiederhergestellt. Der Switch wird jetzt neu gestartet.');
                await this.loadSwitchData(true);
            } catch (error) { alert(error.message); } finally { if (button) button.disabled = false; }
        });
        // Aktualisiert LAG/VLAN-Daten und verarbeitet das VLAN-Formular.
        document.getElementById('lagRefresh')?.addEventListener('click', () => this.loadSwitchConfig('lag'));
        document.getElementById('vlanRefresh')?.addEventListener('click', () => this.loadSwitchConfig('vlan'));
        document.getElementById('newVlanBtn')?.addEventListener('click', () => this.openVlan());
        this.el.vlanForm?.addEventListener('submit', e => this.saveVlan(e));

        // Filtert Tabellenansicht und grafische Portansicht gleichzeitig.
        if (this.el.portSearch) {
            this.el.portSearch.addEventListener('input', () => {
                this.renderPorts();
                this.renderVisualPorts();
            });
        }

        // Zeigt die unverarbeiteten Gerätedaten ausschließlich auf Benutzeraktion.
        if (this.el.showJsonBtn && this.el.jsonModal) {
            this.el.showJsonBtn.addEventListener('click', () => {
                if (this.el.jsonViewer) {
                    this.el.jsonViewer.textContent = JSON.stringify(this.currentData || {}, null, 2);
                }
                this.el.jsonModal.classList.add('active');
            });
        }

        if (this.el.closeJsonModal && this.el.jsonModal) {
            this.el.closeJsonModal.addEventListener('click', () => this.el.jsonModal.classList.remove('active'));
        }
    }

    /** Loads the initial cache snapshot and enables foreground auto-refresh. */
    async start() {
        await this.loadOverview();
        this.startAutoRefresh();
    }

    /** Startet den periodischen Aktualisierungszyklus der Dashboard-Daten. */
    startAutoRefresh() {
        if (this.autoRefreshTimer) clearInterval(this.autoRefreshTimer);
        this.autoRefreshTimer = setInterval(() => this.autoRefreshTick(), this.autoRefreshMs);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this.autoRefreshTick();
        });
    }

    /** Aktualisiert Daten nur, wenn die Seite sichtbar und eine Abfrage sinnvoll ist. */
    async autoRefreshTick() {
        if (document.hidden || this.refreshInProgress) return;
        const currentView = window.AppShell?.currentView || 'dashboard';
        if (currentView === 'logs' || currentView === 'users') return;

        if (currentView === 'dashboard' && !document.getElementById('switchDetail')?.classList.contains('hidden')) {
            await this.loadSwitchData(false, true);
            await this.loadOverview(false);
            return;
        }

        if (currentView === 'dashboard' || currentView === 'switches') {
            await this.loadOverview(false);
            if (currentView === 'dashboard') window.AppShell?.renderSwitchOverview(this.switches);
            if (currentView === 'switches') window.AppShell?.renderSwitchesManagement(this.switches);
        }
    }

    /** Forces a sequential live refresh for all visible switches. */
    async refreshAllSwitches(silent = true) {
        if (this.refreshInProgress) return;
        this.refreshInProgress = true;
        try {
            if (!this.switches.length) await this.loadOverview(false);
            // Sequentiell aktualisieren, damit viele Switches das Netzwerk nicht gleichzeitig belasten.
            for (const sw of this.switches) {
                try {
                    const url = `api/switch.php?id=${encodeURIComponent(sw.id)}&force=1${silent ? '&silent=1' : ''}`;
                    const res = await fetch(url, { cache: 'no-store' });
                    await res.json();
                } catch (err) {
                    console.warn(`Live-Aktualisierung für ${sw.id} fehlgeschlagen:`, err);
                }
            }
            await this.loadOverview(false);
            const detailVisible = !document.getElementById('switchDetail')?.classList.contains('hidden');
            if (window.AppShell?.currentView === 'dashboard' && !detailVisible) {
                window.AppShell.renderSwitchOverview(this.switches);
            }
            if (this.selectedSwitch && detailVisible) {
                await this.loadSwitchData(false, true);
            }
        } finally {
            this.refreshInProgress = false;
        }
    }

    /** Loads cached overview rows and optionally rerenders dependent views. */
    async loadOverview(render = true) {
        try {
            const res = await fetch('api/overview.php');
            const data = await res.json();
            this.switches = data.switches || [];
            if (!this.selectedSwitch && this.switches.length > 0) this.selectedSwitch = this.switches[0].id;
            this.renderSidebar();

            if (window.AppShell && render) window.AppShell.renderSwitchOverview(this.switches);
            if (window.AppShell) window.AppShell.renderSwitchesManagement(this.switches);
        } catch (err) {
            console.error('Fehler beim Laden der Übersicht:', err);
        }
    }

    /** Loads one selected switch from cache or directly from SwOS. */
    async loadSwitchData(force = false, silent = false) {
        if (!this.selectedSwitch) return;

        try {
            const url = `api/switch.php?id=${encodeURIComponent(this.selectedSwitch)}` + (force ? '&force=1' : '&cache_only=1') + (silent ? '&silent=1' : '');
            const res = await fetch(url);
            const data = await res.json();
            this.currentData = data;
            this.renderDashboard();
            if (this.configLoadedFor !== this.selectedSwitch) this.loadSwitchConfig();
        } catch (err) {
            console.error('Fehler beim Laden der Switch-Daten:', err);
        }
    }

    /** Zeichnet die zugänglichen Switches in der Seitenleiste. */
    renderSidebar() {
        if (!this.el.switchList) return;
        this.el.switchList.innerHTML = '';

        if (this.switches.length === 0) {
            this.el.switchList.innerHTML = '<div style="padding:0.5rem; color:#64748b; font-size:0.8rem;">Keine Switches vorhanden</div>';
            return;
        }

        this.switches.forEach(sw => {
            const item = document.createElement('a');
            item.className = `switch-item ${sw.id === this.selectedSwitch ? 'active' : ''}`;
            item.href = '#';
            
            const isOnline = sw.is_online == 1;

            item.innerHTML = `
                <span>${sw.name}</span>
                <span class="switch-item-status ${isOnline ? 'online' : ''}"></span>
            `;

            item.addEventListener('click', (e) => {
                e.preventDefault();
                this.selectedSwitch = sw.id;
                this.switchConfig = null;
                this.configLoadedFor = null;
                window.AppShell?.showDetail();
                this.renderSidebar();
                this.loadSwitchData(false);
            });

            this.el.switchList.appendChild(item);
        });
    }

    /** Überträgt die aktuellen Switch-Daten in Kennzahlen und Detailbereiche. */
    renderDashboard() {
        if (!this.currentData) return;

        const info = this.currentData.info || {};
        if (this.el.headerSwitchName) this.el.headerSwitchName.textContent = this.currentData.name || 'Unbekannt';
        if (this.el.headerSwitchInfo) this.el.headerSwitchInfo.textContent = `${this.currentData.host || '—'} • MAC: ${info.mac || '—'}`;

        if (this.el.cacheBadge) {
            this.el.cacheBadge.style.display = 'inline-block';
            this.el.cacheBadge.textContent = this.currentData.cached ? 'DB CACHE' : 'LIVE SYNC';
            this.el.cacheBadge.className = `badge ${this.currentData.cached ? 'badge-cache' : 'badge-success'}`;
        }

        if (this.el.modelValue) this.el.modelValue.textContent = info.model || '—';
        if (this.el.swosValue) this.el.swosValue.textContent = info.version || '—';
        if (this.el.uptimeValue) this.el.uptimeValue.textContent = info.uptime || '—';
        if (this.el.temperatureValue) this.el.temperatureValue.textContent = info.temperature ? `${info.temperature} °C` : '—';
        if (this.el.voltageValue) this.el.voltageValue.textContent = info.voltage ? `${info.voltage} V` : '—';

        this.renderChart();
        this.renderPorts();
        this.renderVisualPorts();

        const timeStr = this.currentData.updated_at ? new Date(this.currentData.updated_at * 1000).toLocaleTimeString('de-DE') : new Date().toLocaleTimeString('de-DE');
        if (this.el.lastUpdate) {
            this.el.lastUpdate.textContent = `Letztes Update: ${timeStr}` + (this.currentData.error ? ` (Fehler: ${this.currentData.error})` : '');
        }
    }

    /** Zeichnet die kompakte physische Portübersicht. */
    renderVisualPorts() {
        const container = this.el.visualPortsContainer;
        if (!container) return;
        container.innerHTML = '';

        const ports = this.currentData?.ports || [];
        const query = this.el.portSearch ? this.el.portSearch.value.toLowerCase().trim() : '';
        const filtered = ports.filter(p => (p.name || '').toLowerCase().includes(query) || String(p.port).includes(query));

        if (filtered.length === 0) {
            container.innerHTML = '<span style="color:var(--text-muted); font-size:0.75rem;">Keine Ports vorhanden</span>';
            return;
        }

        filtered.forEach(p => {
            const isUp = p.link === true || p.status === 'UP' || p.status === 'up';
            const card = document.createElement('div');
            card.className = `port-visual-card ${isUp ? 'online' : 'offline'} ${p.enabled === false ? 'disabled' : ''}`;
            
            card.innerHTML = `
                <div class="port-chip">
                    <div class="port-chip-led"></div>
                    <div class="port-chip-pins"></div>
                </div>
                <span class="port-visual-label" title="Port ${p.port}">#${p.port}</span>
            `;
            
            container.appendChild(card);
        });
    }

    /** Erzeugt oder aktualisiert das Verkehrsdiagramm mit Chart.js. */
    renderChart() {
        if (!this.el.chartCanvas) return;
        if (!window.Chart) {
            window.loadChartLibrary().then(() => this.renderChart()).catch(error => console.warn(error.message));
            return;
        }

        const ports = this.currentData?.ports || [];
        const labels = ports.map(p => `#${p.port}`);
        const rxPackets = ports.map(p => p.rx_packets || 0);
        const txPackets = ports.map(p => p.tx_packets || 0);

        if (this.chart) {
            this.chart.destroy();
        }

        const ctx = this.el.chartCanvas.getContext('2d');
        this.chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Empfangen (RX Packets)',
                        data: rxPackets,
                        backgroundColor: '#00e676',
                        borderRadius: 4
                    },
                    {
                        label: 'Gesendet (TX Packets)',
                        data: txPackets,
                        backgroundColor: '#38bdf8',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: '#94a3b8', font: { family: 'sans-serif', size: 11 } }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: '#64748b' },
                        grid: { color: '#1e293b' }
                    },
                    y: {
                        ticks: { color: '#64748b' },
                        grid: { color: '#1e293b' }
                    }
                }
            }
        });
    }

    /** Zeichnet die Porttabelle und bindet erlaubte Portaktionen. */
    renderPorts() {
        if (!this.el.portsBody) return;

        const ports = this.currentData?.ports || [];
        const query = this.el.portSearch ? this.el.portSearch.value.toLowerCase().trim() : '';

        const filtered = ports.filter(p => (p.name || '').toLowerCase().includes(query) || String(p.port).includes(query));
        
        const onlineCount = ports.filter(p => p.link).length;
        if (this.el.portSummary) this.el.portSummary.textContent = `PORT STATUS & STATISTICS`;
        if (this.el.portSub) this.el.portSub.textContent = `Ports (${onlineCount}/${ports.length} Online)`;

        if (!filtered.length) {
            this.el.portsBody.innerHTML = `<tr><td colspan="12" class="table-empty">${this.currentData?.error ? 'Verbindung zum Switch fehlgeschlagen' : 'Keine Ports verfügbar.'}</td></tr>`;
            return;
        }

        const canManage = this.permissions.has('switch.port.manage');
        this.el.portsBody.innerHTML = filtered.map(p => {
          const enabled = p.enabled !== false;
          const auto = p.auto_negotiation !== false;
          return `
            <tr>
                <td><strong>#${p.port}</strong> ${this.escape(p.name || '')}</td>
                <td><span class="badge ${enabled ? 'badge-success' : 'badge-muted'}">${enabled ? 'ENABLED' : 'DISABLED'}</span></td>
                <td><span class="badge ${p.link ? 'badge-success' : 'badge-muted'}">${p.link ? 'UP' : 'DOWN'}</span></td>
                <td>${p.speed || '—'}</td>
                <td>${p.duplex || '—'}</td>
                <td>${canManage ? `<div class="link-controls"><select class="mini-select port-autoneg" data-port="${p.port}"><option value="1" ${auto?'selected':''}>Ein</option><option value="0" ${!auto?'selected':''}>Aus</option></select><select class="mini-select port-speed" data-port="${p.port}" ${auto?'disabled':''}>${this.speedOptions(auto?null:p.configured_speed_code,p.speed_options)}</select><button type="button" class="btn btn-small port-link-save" data-port="${p.port}">Speichern</button></div>` : `<span class="badge ${auto?'badge-success':'badge-muted'}">${auto?'EIN':'AUS'}</span>`}</td>
                <td>${this.formatBytes(p.rx_bytes)}</td>
                <td>${this.formatBytes(p.tx_bytes)}</td>
                <td>${(p.rx_packets || 0).toLocaleString('de-DE')}</td>
                <td>${(p.tx_packets || 0).toLocaleString('de-DE')}</td>
                <td>${(p.rx_errors || 0) + (p.tx_errors || 0)}</td>
                ${canManage ? `<td class="port-actions"><button type="button" class="btn btn-small port-toggle" data-port="${p.port}" data-enabled="${enabled ? '1' : '0'}">${enabled ? 'Deaktivieren' : 'Aktivieren'}</button><button type="button" class="btn btn-small port-rename" data-port="${p.port}" data-name="${this.escape(p.name || '')}">Umbenennen</button></td>` : ''}
            </tr>
        `}).join('');
        this.el.portsBody.querySelectorAll('.port-toggle').forEach(button => button.addEventListener('click', async () => {
            const enabled = button.dataset.enabled === '1';
            if (!confirm(`Port ${button.dataset.port} wirklich ${enabled ? 'deaktivieren' : 'aktivieren'}?`)) return;
            button.disabled = true;
            try { await this.postAction(enabled ? 'port_disable' : 'port_enable', {port: button.dataset.port}); await this.loadSwitchData(true); }
            catch (error) { alert(error.message); } finally { button.disabled = false; }
        }));
        this.el.portsBody.querySelectorAll('.port-rename').forEach(button => button.addEventListener('click', async () => {
            const name = prompt(`Neuer Name für Port ${button.dataset.port}:`, button.dataset.name || '');
            if (name === null) return;
            button.disabled = true;
            try { await this.postAction('port_rename', {port: button.dataset.port, name}); await this.loadSwitchData(true); }
            catch (error) { alert(error.message); } finally { button.disabled = false; }
        }));
        this.el.portsBody.querySelectorAll('.port-autoneg').forEach(select => select.addEventListener('change', () => {
            const speed = this.el.portsBody.querySelector(`.port-speed[data-port="${select.dataset.port}"]`);
            if (speed) speed.disabled = select.value === '1';
        }));
        this.el.portsBody.querySelectorAll('.port-link-save').forEach(button => button.addEventListener('click', async () => {
            const auto = this.el.portsBody.querySelector(`.port-autoneg[data-port="${button.dataset.port}"]`)?.value === '1';
            const speed = this.el.portsBody.querySelector(`.port-speed[data-port="${button.dataset.port}"]`)?.value || '';
            if (!auto && !confirm(`Port ${button.dataset.port} auf die gewählte Geschwindigkeit fest einstellen?`)) return;
            button.disabled = true;
            try { await this.postAction('port_link', {port:button.dataset.port,auto_negotiation:auto?'1':'0',speed_code:speed}); await this.loadSwitchData(true); }
            catch (error) { alert(error.message); } finally { button.disabled = false; }
        }));
    }

    /** Erzeugt die verfügbaren Portgeschwindigkeiten als Optionsliste. */
    speedOptions(selected,available=[]) {
        const options = Array.isArray(available)&&available.length?available.map(item=>[Number(item.value),item.label]):[[0,'10 Mbit/s'],[1,'100 Mbit/s'],[2,'1 Gbit/s'],[3,'10 Gbit/s']];
        if (selected !== null && selected !== undefined && !options.some(([value]) => Number(value) === Number(selected))) options.push([Number(selected),`Firmware-Code ${selected}`]);
        return options.map(([value,label]) => `<option value="${value}" ${Number(value)===Number(selected)?'selected':''}>${label}</option>`).join('');
    }

    /** Fetches live LAG/VLAN configuration for the selected switch. */
    async loadSwitchConfig(section='all') {
        if (!this.selectedSwitch) return;
        const loadLag=section==='all'||section==='lag',loadVlan=section==='all'||section==='vlan';
        if(loadLag&&this.el.lagBody)this.el.lagBody.innerHTML='<tr><td colspan="6" class="table-empty">Lade LAG-Konfiguration…</td></tr>';
        if(loadVlan&&this.el.vlanPortBody)this.el.vlanPortBody.innerHTML='<tr><td colspan="6" class="table-empty">Lade VLAN-Konfiguration…</td></tr>';
        try{
            const response=await fetch(`api/switch_config.php?id=${encodeURIComponent(this.selectedSwitch)}&section=${encodeURIComponent(section)}&_=${Date.now()}`,{cache:'no-store'});
            const data=await response.json();if(!response.ok)throw new Error(data.error||'Switch-Konfiguration konnte nicht geladen werden.');
            this.switchConfig={...(this.switchConfig||{}),...(data.config||{})};this.configLoadedFor=this.selectedSwitch;
            if(loadLag)this.renderLag(data.errors?.lag||'');if(loadVlan)this.renderVlan(data.errors?.vlan||'');
        }catch(error){if(loadLag)this.showConfigError(this.el.lagError,error.message);if(loadVlan)this.showConfigError(this.el.vlanError,error.message);}
    }

    /** Zeigt einen Konfigurationsfehler an oder blendet den Hinweis aus. */
    showConfigError(element,message=''){if(!element)return;element.textContent=message;element.classList.toggle('hidden',!message);}

    /** Zeichnet die LAG-Konfiguration und bindet Speicheraktionen. */
    renderLag(error='') {
        this.showConfigError(this.el.lagError,error);if(!this.el.lagBody)return;
        const rows=this.switchConfig?.lag||[],canManage=this.permissions.has('switch.lag.manage');
        if(error||!rows.length){this.el.lagBody.innerHTML=`<tr><td colspan="6" class="table-empty">${this.escape(error||'Keine LAG-Daten verfügbar.')}</td></tr>`;return;}
        this.el.lagBody.innerHTML=rows.map(row=>`<tr><td><strong>#${row.port}</strong> ${this.escape(this.portName(row.port))}</td><td>${canManage?`<select class="mini-select lag-mode" data-port="${row.port}"><option value="0" ${Number(row.mode)===0?'selected':''}>Passive LACP</option><option value="1" ${Number(row.mode)===1?'selected':''}>Active LACP</option><option value="2" ${Number(row.mode)===2?'selected':''}>Static</option></select>`:this.escape(row.mode_label)}</td><td>${canManage?`<select class="mini-select lag-group" data-port="${row.port}">${Array.from({length:17},(_,i)=>`<option value="${i}" ${i===Number(row.group)?'selected':''}>${i===0?'—':i}</option>`).join('')}</select>`:(row.group||'—')}</td><td>${row.trunk||'—'}</td><td>${this.escape(row.partner||'—')}</td>${canManage?`<td><button class="btn btn-small lag-save" data-port="${row.port}">Speichern</button></td>`:''}</tr>`).join('');
        this.el.lagBody.querySelectorAll('.lag-save').forEach(button=>button.addEventListener('click',async()=>{const port=button.dataset.port,mode=this.el.lagBody.querySelector(`.lag-mode[data-port="${port}"]`).value,group=this.el.lagBody.querySelector(`.lag-group[data-port="${port}"]`).value;if(Number(mode)===2&&Number(group)===0){alert('Für Static LAG muss eine Gruppe zwischen 1 und 16 gewählt werden.');return;}button.disabled=true;try{await this.postAction('lag_save',{port,mode,group});await this.loadSwitchConfig('lag');}catch(error){alert(error.message);}finally{button.disabled=false;}}));
    }

    /** Zeichnet Port-VLANs sowie die VLAN-Tabelle und deren Aktionen. */
    renderVlan(error='') {
        this.showConfigError(this.el.vlanError,error);const config=this.switchConfig?.vlan,canManage=this.permissions.has('switch.vlan.manage');
        if(!this.el.vlanPortBody||!this.el.vlanBody)return;
        if(error||!config){const message=this.escape(error||'Keine VLAN-Daten verfügbar.');this.el.vlanPortBody.innerHTML=`<tr><td colspan="6" class="table-empty">${message}</td></tr>`;this.el.vlanBody.innerHTML=`<tr><td colspan="4" class="table-empty">${message}</td></tr>`;return;}
        const newVlanButton=document.getElementById('newVlanBtn');if(newVlanButton){newVlanButton.disabled=!!config.table_error;newVlanButton.title=config.table_error||'';}
        this.el.vlanPortBody.innerHTML=(config.ports||[]).map(row=>`<tr><td><strong>#${row.port}</strong> ${this.escape(this.portName(row.port))}</td><td>${canManage?`<input class="mini-input vlan-pvid" data-port="${row.port}" type="number" min="1" max="4095" value="${row.pvid}">`:row.pvid}</td><td>${canManage?`<select class="mini-select vlan-mode" data-port="${row.port}"><option value="0" ${Number(row.mode)===0?'selected':''}>Disabled</option><option value="1" ${Number(row.mode)===1?'selected':''}>Optional</option><option value="2" ${Number(row.mode)===2?'selected':''}>Enabled</option><option value="3" ${Number(row.mode)===3?'selected':''}>Strict</option></select>`:['Disabled','Optional','Enabled','Strict'][row.mode]||row.mode}</td><td>${canManage?`<select class="mini-select vlan-receive" data-port="${row.port}"><option value="0" ${Number(row.receive)===0?'selected':''}>Any</option><option value="1" ${Number(row.receive)===1?'selected':''}>Only tagged</option><option value="2" ${Number(row.receive)===2?'selected':''}>Only untagged</option></select>`:['Any','Only tagged','Only untagged'][row.receive]||row.receive}</td><td>${canManage?`<input class="vlan-force" data-port="${row.port}" type="checkbox" ${row.force_vlan?'checked':''}>`:(row.force_vlan?'Ja':'Nein')}</td>${canManage?`<td><button class="btn btn-small vlan-port-save" data-port="${row.port}">Speichern</button></td>`:''}</tr>`).join('')||'<tr><td colspan="6" class="table-empty">Keine Port-VLAN-Daten.</td></tr>';
        this.el.vlanPortBody.querySelectorAll('.vlan-port-save').forEach(button=>button.addEventListener('click',async()=>{const port=button.dataset.port;button.disabled=true;try{await this.postAction('vlan_port_save',{port,pvid:this.el.vlanPortBody.querySelector(`.vlan-pvid[data-port="${port}"]`).value,mode:this.el.vlanPortBody.querySelector(`.vlan-mode[data-port="${port}"]`).value,receive:this.el.vlanPortBody.querySelector(`.vlan-receive[data-port="${port}"]`).value,force_vlan:this.el.vlanPortBody.querySelector(`.vlan-force[data-port="${port}"]`).checked?'1':'0'});await this.loadSwitchConfig('vlan');}catch(error){alert(error.message);}finally{button.disabled=false;}}));
        this.el.vlanBody.innerHTML=config.table_error?`<tr><td colspan="4" class="table-empty">${this.escape(config.table_error)}</td></tr>`:((config.vlans||[]).map(row=>{const members=[];for(let port=1;port<=Number(config.port_count||32);port++)if((Number(row.members_mask)&(1<<(port-1)))!==0)members.push(`<span class="port-tag">#${port}</span>`);return `<tr><td><strong>${row.id}</strong></td><td>${this.escape(row.name||'—')}</td><td>${members.join(' ')||'—'}</td>${canManage?`<td><button class="btn btn-small vlan-edit" data-index="${row.index}">Bearbeiten</button> <button class="btn btn-small btn-danger vlan-delete" data-index="${row.index}" data-id="${row.id}">Löschen</button></td>`:''}</tr>`;}).join('')||'<tr><td colspan="4" class="table-empty">Noch keine VLANs vorhanden.</td></tr>');
        this.el.vlanBody.querySelectorAll('.vlan-edit').forEach(button=>button.addEventListener('click',()=>this.openVlan(Number(button.dataset.index))));
        this.el.vlanBody.querySelectorAll('.vlan-delete').forEach(button=>button.addEventListener('click',async()=>{if(!confirm(`VLAN ${button.dataset.id} wirklich löschen?`))return;button.disabled=true;try{await this.postAction('vlan_delete',{index:button.dataset.index});await this.loadSwitchConfig('vlan');}catch(error){alert(error.message);}finally{button.disabled=false;}}));
    }

    /** Ermittelt den Anzeigenamen eines Ports aus den aktuellen Gerätedaten. */
    portName(port){return this.currentData?.ports?.find(item=>Number(item.port)===Number(port))?.name||`Port ${port}`;}

    /** Öffnet den VLAN-Dialog zum Anlegen oder Bearbeiten. */
    openVlan(index=null) {
        const config=this.switchConfig?.vlan;if(!config||!this.el.vlanModal)return;const row=index===null?null:(config.vlans||[]).find(item=>Number(item.index)===Number(index));
        document.getElementById('vlanModalTitle').textContent=row?`VLAN ${row.id} bearbeiten`:'VLAN anlegen';document.getElementById('vlanIndex').value=row?.index??'';document.getElementById('vlanId').value=row?.id??'';document.getElementById('vlanName').value=row?.name??'';
        document.getElementById('vlanMemberChecks').innerHTML=Array.from({length:Number(config.port_count||0)},(_,i)=>{const port=i+1,checked=row?((Number(row.members_mask)&(1<<i))!==0):false;return `<label class="check-item"><input type="checkbox" data-port="${port}" ${checked?'checked':''}><span>#${port} ${this.escape(this.portName(port))}</span></label>`;}).join('');this.el.vlanModal.classList.add('active');
    }

    /** Speichert ein VLAN samt ausgewählten Mitgliedsports. */
    async saveVlan(event) {
        event.preventDefault();const button=event.submitter,members=[...document.querySelectorAll('#vlanMemberChecks input:checked')].map(input=>input.dataset.port);button.disabled=true;
        try{await this.postAction('vlan_save',{index:document.getElementById('vlanIndex').value,vlan_id:document.getElementById('vlanId').value,name:document.getElementById('vlanName').value,members:JSON.stringify(members)});this.el.vlanModal.classList.remove('active');event.target.reset();await this.loadSwitchConfig('vlan');}catch(error){alert(error.message);}finally{button.disabled=false;}
    }

    /** Submits one device action; the global fetch wrapper adds the CSRF token. */
    async postAction(action, fields = {}) {
        if (!this.selectedSwitch) throw new Error('Kein Switch ausgewählt.');
        const data = new FormData(); data.append('action', action); data.append('id', this.selectedSwitch);
        Object.entries(fields).forEach(([key, value]) => data.append(key, value));
        const response = await fetch('api/switch_actions.php', {method: 'POST', body: data});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || 'Switch-Aktion fehlgeschlagen.');
        return result;
    }

    /** Maskiert dynamische Werte vor dem Einfügen in HTML. */
    escape(value) {
        return String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    }

    /** Formatiert eine Byte-Anzahl als gut lesbare Größenangabe. */
    formatBytes(b) {
        if (!b) return '0 B';
        if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
        if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB';
        if (b >= 1024) return (b / 1024).toFixed(2) + ' KB';
        return b + ' B';
    }
}

document.addEventListener('DOMContentLoaded', () => new SwitchlyDashboard());
