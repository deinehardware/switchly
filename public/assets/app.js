'use strict';

/**
 * Application-shell controller.
 *
 * Handles hash navigation and the stack-wide administration views: logs,
 * monitoring, users, permissions, alerts and OpenID Connect. Device detail
 * behavior is intentionally isolated in dashboard.js.
 */

/** Coordinates top-level views and administration dialogs. */
class AppShell {
  /** Initialisiert Zustände, DOM-Verweise und die ersten Datenabrufe. */
  constructor(){
    this.isAdmin=document.body.dataset.role==='admin';
    try{this.permissions=new Set(JSON.parse(document.body.dataset.permissions||'[]'));}catch(_){this.permissions=new Set();}
    this.views={
      dashboard:document.getElementById('view-dashboard'),
      switches:document.getElementById('view-switches'),
      logs:document.getElementById('view-logs'),
      users:document.getElementById('view-users')
      ,monitoring:document.getElementById('view-monitoring')
      ,permissions:document.getElementById('view-permissions')
      ,alerts:document.getElementById('view-alerts')
      ,openid:document.getElementById('view-openid')
    };
    this.currentView='dashboard';
    this.dashboardLogs=[];
    this.fullLogs=[];
    this.monitorChart=null;
    this.permissionData=null;
    this.bind();
    this.handleHash();
    if(this.has('logs.view'))this.loadDashboardLogs();
  }

  /** Registers navigation, filter, form and modal event handlers. */
  bind(){
    // Wechselt die Hauptansicht, ohne eine neue Seite zu laden.
    document.querySelectorAll('.nav-item[data-view]').forEach(a=>a.addEventListener('click',e=>{
      e.preventDefault();
      this.show(a.dataset.view);
      history.replaceState(null,'','#'+a.dataset.view);
    }));

    // Verbindet Protokollfilter, Suche, Aktualisierung und Löschaktion.
    document.getElementById('logsRefresh')?.addEventListener('click',()=>this.loadLogs());
    document.getElementById('logsClear')?.addEventListener('click',()=>this.clearLogs());
    document.getElementById('logLevelFilter')?.addEventListener('change',()=>this.loadLogs());
    document.getElementById('logScopeFilter')?.addEventListener('change',e=>{const select=document.getElementById('logSwitchFilter');select?.classList.toggle('hidden',e.target.value!=='switch');if(e.target.value==='switch'&&select&&!select.value&&select.options.length>1)select.selectedIndex=1;this.loadLogs();});
    document.getElementById('logSwitchFilter')?.addEventListener('change',()=>this.loadLogs());
    document.getElementById('fullLogSearch')?.addEventListener('input',()=>this.renderFullLogs());
    document.getElementById('dashboardLogLevel')?.addEventListener('change',()=>this.loadDashboardLogs());
    document.getElementById('dashboardLogRange')?.addEventListener('change',()=>this.loadDashboardLogs());
    document.getElementById('dashboardLogSearch')?.addEventListener('input',()=>this.renderDashboardLogs());

    // Bedient die globalen Aktionen der Dashboard- und Switch-Übersicht.
    document.getElementById('overviewRefresh')?.addEventListener('click',async e=>{
      const btn=e.currentTarget; this.setBusy(btn,true,'Aktualisiere…');
      try{await window.dashboard?.refreshAllSwitches(false);await this.loadDashboardLogs();}
      finally{this.setBusy(btn,false);}
    });
    document.getElementById('overviewAdd')?.addEventListener('click',()=>document.getElementById('addSwitchBtn')?.click());
    document.getElementById('switchesRefresh')?.addEventListener('click',async e=>{
      const btn=e.currentTarget; this.setBusy(btn,true,'Aktualisiere…');
      try{await window.dashboard?.refreshAllSwitches(false);}
      finally{this.setBusy(btn,false);}
    });
    document.getElementById('switchesAdd')?.addEventListener('click',()=>document.getElementById('addSwitchBtn')?.click());
    // Lädt Monitoring-Daten bei manueller Aktualisierung oder Filteränderung neu.
    document.getElementById('monitorRefresh')?.addEventListener('click',()=>this.loadMonitoring());
    document.getElementById('monitorScope')?.addEventListener('change',()=>this.loadMonitoring());
    document.getElementById('monitorRange')?.addEventListener('change',()=>this.loadMonitoring());

    // Öffnet und verarbeitet Benutzer-, Rechte- und Gruppenformulare.
    document.getElementById('newUserBtn')?.addEventListener('click',()=>this.openUser());
    document.getElementById('closeUserModal')?.addEventListener('click',()=>this.closeModal('userModal'));
    document.getElementById('userForm')?.addEventListener('submit',e=>this.saveUser(e));
    document.getElementById('newGroupBtn')?.addEventListener('click',async()=>{if(!this.permissionData)await this.loadPermissions();this.openGroup();});
    document.getElementById('permissionForm')?.addEventListener('submit',e=>this.saveUserPermissions(e));
    document.getElementById('groupForm')?.addEventListener('submit',e=>this.saveGroup(e));
    // Vereinheitlicht das Schließen aller Dialoge per Hintergrund, Taste oder Schaltfläche.
    ['userModal','addModal','jsonModal','restoreModal','permissionModal','groupModal','vlanModal'].forEach(id=>this.bindModal(id));
    document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>this.closeModal(b.dataset.close)));
    document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.querySelectorAll('.modal.active').forEach(m=>m.classList.remove('active'));document.querySelectorAll('.switch-card-menu-wrap.open').forEach(m=>{m.classList.remove('open');m.querySelector('.kebab-btn')?.setAttribute('aria-expanded','false');});}});
    document.addEventListener('click',()=>document.querySelectorAll('.switch-card-menu-wrap.open').forEach(m=>{m.classList.remove('open');m.querySelector('.kebab-btn')?.setAttribute('aria-expanded','false');}));
    // Verarbeitet Speichern und Verbindungstests der Alarm- und OIDC-Konfiguration.
    document.getElementById('alertForm')?.addEventListener('submit',e=>this.saveAlerts(e,'save'));
    document.getElementById('alertTestBtn')?.addEventListener('click',e=>this.saveAlerts(e,'test'));
    document.getElementById('oidcForm')?.addEventListener('submit',e=>this.saveOidc(e,'save'));
    document.getElementById('oidcTestBtn')?.addEventListener('click',e=>this.saveOidc(e,'test'));
    document.getElementById('oidcBaseUrl')?.addEventListener('input',e=>{const callback=document.getElementById('oidcCallbackUrl');if(callback&&e.target.value.trim())callback.value=e.target.value.trim().replace(/\/$/,'')+'/sso.php';});
  }

  /** Schaltet eine Schaltfläche zwischen normalem und wartendem Zustand um. */
  setBusy(btn,busy,label='Bitte warten…'){
    if(!btn)return;
    if(busy){btn.dataset.oldHtml=btn.innerHTML;btn.disabled=true;btn.textContent=label;}
    else{btn.disabled=false;if(btn.dataset.oldHtml){btn.innerHTML=btn.dataset.oldHtml;delete btn.dataset.oldHtml;}}
  }

  /** Prüft, ob der angemeldete Benutzer eine bestimmte Berechtigung besitzt. */
  has(code){return this.permissions.has(code);}

  /** Schließt einen Dialog, wenn direkt auf dessen Hintergrund geklickt wird. */
  bindModal(id){const el=document.getElementById(id);el?.addEventListener('click',e=>{if(e.target===el)el.classList.remove('active');});}
  /** Schließt den angegebenen Dialog über seine Element-ID. */
  closeModal(id){document.getElementById(id)?.classList.remove('active');}

  /** Öffnet die durch den URL-Hash bezeichnete Ansicht. */
  handleHash(){
    const hash=location.hash.replace('#','');
    if(hash&&this.views[hash])this.show(hash);else this.show('dashboard');
  }

  /** Activates one authorized view and loads its data on demand. */
  show(view){
    if(!this.views[view])view='dashboard';
    this.currentView=view;
    Object.values(this.views).forEach(v=>v?.classList.remove('active-view'));
    this.views[view]?.classList.add('active-view');
    document.querySelectorAll('.nav-item[data-view]').forEach(a=>a.classList.toggle('active',a.dataset.view===view));
    if(view==='logs')this.loadLogs();
    if(view==='users')this.loadUsers();
    if(view==='monitoring')this.loadMonitoring();
    if(view==='permissions')this.loadPermissions();
    if(view==='alerts')this.loadAlerts();
    if(view==='openid')this.loadOidc();
    if(view==='switches'){
      this.renderSwitchesManagement(window.dashboard?.switches||[]);
      window.dashboard?.loadOverview(false);
    }
    if(view==='dashboard')this.renderSwitchOverview(window.dashboard?.switches||[]);
  }

  /** Wechselt von der Übersicht zur Detailansicht eines Switches. */
  showDetail(){this.show('dashboard');document.getElementById('switchOverviewGrid')?.classList.add('hidden');document.getElementById('dashboardLogsCard')?.classList.add('hidden');document.getElementById('switchDetail')?.classList.remove('hidden');}
  /** Blendet die Switch-Übersicht ein und die Detailansicht aus. */
  showOverview(){document.getElementById('switchOverviewGrid')?.classList.remove('hidden');document.getElementById('dashboardLogsCard')?.classList.remove('hidden');document.getElementById('switchDetail')?.classList.add('hidden');}

  /** Renders cached switch cards without inserting unescaped device values. */
  renderSwitchOverview(switches){
    const grid=document.getElementById('switchOverviewGrid');if(!grid)return;grid.innerHTML='';this.showOverview();this.populateSwitchSelectors(switches);
    switches.forEach((sw,index)=>{
      const online=Number(sw.is_online)===1;
      const el=document.createElement('article');el.className='overview-switch-card';
      el.innerHTML=`
        <div class="switch-card-top"><span class="switch-id-pill">#${index+1}</span><div class="switch-card-actions"><span class="online-badge ${online?'':'offline'}">${online?'Online':'Offline'}</span><div class="switch-card-menu-wrap"><button type="button" class="kebab-btn" aria-label="Aktionen für ${this.escape(sw.name)}" aria-expanded="false">⋮</button><div class="switch-card-menu" role="menu"><button type="button" class="menu-open" role="menuitem">Öffnen</button><button type="button" class="menu-refresh" role="menuitem">↻ Live aktualisieren</button><button type="button" class="menu-delete danger" role="menuitem">Löschen</button></div></div></div></div>
        <div class="switch-card-title">${this.escape(sw.name)}</div>
        <div class="switch-card-address">${this.escape(sw.host)} : ${this.escape(sw.port)}</div>
        <div class="switch-metrics">
          <div class="metric"><span>Ports</span><strong>${Number(sw.ports_total||0)}</strong><small class="good">↑ ${Number(sw.ports_active||0)} aktiv</small></div>
          <div class="metric"><span>Status</span><strong class="${online?'good':''}">${online?'Online':'Offline'}</strong><div class="metric-bar"><i style="width:${online?'100':'0'}%"></i></div></div>
          <div class="metric"><span>Uptime</span><strong>${this.escape(sw.uptime||'—')}</strong></div>
        </div>
        <button type="button" class="switch-open-btn">Öffnen &nbsp;→</button>`;
      if(!this.has('switches.manage'))el.querySelector('.menu-delete')?.remove();
      el.querySelector('.switch-open-btn').addEventListener('click',()=>this.openSwitch(sw));

      const menuWrap=el.querySelector('.switch-card-menu-wrap');
      const menuBtn=el.querySelector('.kebab-btn');
      const menu=el.querySelector('.switch-card-menu');
      const closeMenu=()=>{menuWrap?.classList.remove('open');menuBtn?.setAttribute('aria-expanded','false');};
      menuBtn?.addEventListener('click',e=>{
        e.stopPropagation();
        document.querySelectorAll('.switch-card-menu-wrap.open').forEach(other=>{if(other!==menuWrap){other.classList.remove('open');other.querySelector('.kebab-btn')?.setAttribute('aria-expanded','false');}});
        const open=!menuWrap.classList.contains('open');
        menuWrap.classList.toggle('open',open);
        menuBtn.setAttribute('aria-expanded',open?'true':'false');
      });
      menu?.addEventListener('click',e=>e.stopPropagation());
      el.querySelector('.menu-open')?.addEventListener('click',()=>{closeMenu();this.openSwitch(sw);});
      el.querySelector('.menu-refresh')?.addEventListener('click',async e=>{
        const btn=e.currentTarget;closeMenu();this.setBusy(btn,true,'Aktualisiere…');
        try{
          window.dashboard.selectedSwitch=sw.id;
          await window.dashboard.loadSwitchData(true,true);
          await window.dashboard.loadOverview(false);
          this.renderSwitchOverview(window.dashboard.switches);
          if(this.isAdmin)await this.loadDashboardLogs();
        }catch(err){alert(err.message||'Switch konnte nicht aktualisiert werden.');}
        finally{this.setBusy(btn,false);}
      });
      el.querySelector('.menu-delete')?.addEventListener('click',async()=>{
        closeMenu();
        if(!confirm(`Switch "${sw.name}" wirklich löschen?`))return;
        const f=new FormData();f.append('action','delete');f.append('id',sw.id);
        try{
          const r=await fetch('api/manage_switches.php',{method:'POST',body:f,cache:'no-store'});
          const d=await r.json();if(!r.ok||!d.success)throw new Error(d.error||'Switch konnte nicht gelöscht werden.');
          if(window.dashboard.selectedSwitch===sw.id)window.dashboard.selectedSwitch=null;
          await window.dashboard.loadOverview(false);
          this.renderSwitchOverview(window.dashboard.switches);
          this.renderSwitchesManagement(window.dashboard.switches);
          if(this.isAdmin)await this.loadDashboardLogs();
        }catch(err){alert(err.message||'Switch konnte nicht gelöscht werden.');}
      });
      grid.appendChild(el);
    });
    if(this.has('switches.manage')){const add=document.createElement('button');add.type='button';add.className='add-switch-tile';add.innerHTML='<div class="add-switch-inner"><div class="add-circle">＋</div><strong>Switch hinzufügen</strong><span>Neuen MikroTik Switch überwachen</span></div>';add.addEventListener('click',()=>document.getElementById('addSwitchBtn')?.click());grid.appendChild(add);}
  }

  /** Erzeugt die Verwaltungsansicht samt Geräteaktionen. */
  renderSwitchesManagement(switches){
    const grid=document.getElementById('switchesManagementGrid');if(!grid)return;
    if(!switches.length){grid.innerHTML=`<div class="empty-management card"><strong>Noch keine Switches vorhanden.</strong><span>${this.has('switches.manage')?'Füge deinen ersten MikroTik SwOS Switch hinzu.':'Es wurden keine Switches für dich freigegeben.'}</span>${this.has('switches.manage')?'<button type="button" class="btn btn-primary">＋ Switch hinzufügen</button>':''}</div>`;grid.querySelector('button')?.addEventListener('click',()=>document.getElementById('addSwitchBtn')?.click());return;}
    grid.innerHTML='';
    switches.forEach((sw,index)=>{
      const online=Number(sw.is_online)===1;
      const card=document.createElement('article');card.className='management-switch-card';
      card.innerHTML=`
        <div class="management-main">
          <div class="management-icon">${index+1}</div>
          <div class="management-copy"><div class="management-title-row"><h3>${this.escape(sw.name)}</h3><span class="online-badge ${online?'':'offline'}">${online?'Online':'Offline'}</span></div><p>${this.escape(sw.host)}:${this.escape(sw.port)}</p><small>Letztes Update: ${sw.updated_at?this.formatUnix(sw.updated_at):'Noch keine Daten'}</small></div>
        </div>
        <div class="management-stats"><span><small>Ports</small><strong>${Number(sw.ports_total||0)}</strong></span><span><small>Aktiv</small><strong class="good">${Number(sw.ports_active||0)}</strong></span><span><small>Uptime</small><strong>${this.escape(sw.uptime||'—')}</strong></span></div>
        <div class="management-actions"><button type="button" class="btn open-management">Öffnen →</button><button type="button" class="btn refresh-management">↻ Live aktualisieren</button>${this.has('switch.backup')?'<button type="button" class="btn backup-management">⇩ Backup</button>':''}${this.has('switch.reboot')?'<button type="button" class="btn btn-danger reboot-management">⏻ Reboot</button>':''}</div>`;
      card.querySelector('.open-management').addEventListener('click',()=>this.openSwitch(sw));
      card.querySelector('.refresh-management').addEventListener('click',async e=>{
        const btn=e.currentTarget;this.setBusy(btn,true,'Aktualisiere…');
        try{window.dashboard.selectedSwitch=sw.id;await window.dashboard.loadSwitchData(true,true);await window.dashboard.loadOverview(false);this.renderSwitchesManagement(window.dashboard.switches);}
        finally{this.setBusy(btn,false);}
      });
      card.querySelector('.backup-management')?.addEventListener('click',()=>{window.location.href=`api/switch_actions.php?action=backup&id=${encodeURIComponent(sw.id)}`;});
      card.querySelector('.reboot-management')?.addEventListener('click',async e=>{if(!confirm(`Switch "${sw.name}" neu starten?`))return;const btn=e.currentTarget;this.setBusy(btn,true,'Starte…');try{window.dashboard.selectedSwitch=sw.id;await window.dashboard.postAction('reboot');alert('Neustart wurde ausgelöst.');}catch(error){alert(error.message);}finally{this.setBusy(btn,false);}});
      grid.appendChild(card);
    });
  }

  /** Wählt einen Switch aus und lädt dessen Detaildaten. */
  openSwitch(sw){this.showDetail();window.dashboard.selectedSwitch=sw.id;window.dashboard.renderSidebar();window.dashboard.loadSwitchData(false);history.replaceState(null,'','#dashboard');}

  /** Returns filtered log rows from the session-authenticated API. */
  async fetchLogs(level='',hours=0,limit=300,scope='all',switchId=''){const url=`api/logs.php?limit=${limit}&level=${encodeURIComponent(level)}&hours=${encodeURIComponent(hours)}&scope=${encodeURIComponent(scope)}&switch_id=${encodeURIComponent(switchId)}&_=${Date.now()}`;const r=await fetch(url,{cache:'no-store'});const d=await r.json();if(!r.ok)throw new Error(d.error||'Logs konnten nicht geladen werden.');return d.logs||[];}
  /** Lädt die kompakten Protokolle für das Dashboard. */
  async loadDashboardLogs(){
    const body=document.getElementById('dashboardLogsBody');if(!body||!this.has('logs.view'))return;body.innerHTML='<tr><td colspan="5" class="table-empty">Lade Logs…</td></tr>';
    try{const level=document.getElementById('dashboardLogLevel')?.value||'';const hours=Number(document.getElementById('dashboardLogRange')?.value||24);this.dashboardLogs=await this.fetchLogs(level,hours,150);this.renderDashboardLogs();}catch(e){body.innerHTML=`<tr><td colspan="5" class="table-empty">${this.escape(e.message)}</td></tr>`;}
  }
  /** Filtert und zeichnet die Protokollzeilen des Dashboards. */
  renderDashboardLogs(){
    const body=document.getElementById('dashboardLogsBody');if(!body)return;const q=(document.getElementById('dashboardLogSearch')?.value||'').trim().toLowerCase();const rows=this.dashboardLogs.filter(x=>!q||[x.created_at,x.level,x.username,x.event,x.message].some(v=>String(v||'').toLowerCase().includes(q))).slice(0,12);
    body.innerHTML=rows.map(x=>`<tr><td>${this.formatDate(x.created_at)}</td><td><span class="log-level ${String(x.level).toLowerCase()}">${this.escape(x.level)}</span></td><td>${this.escape(x.username||'—')}</td><td>${this.escape(x.event)}</td><td>${this.escape(x.message||'')}</td></tr>`).join('')||'<tr><td colspan="5" class="table-empty">Keine Logs vorhanden.</td></tr>';
    const count=document.getElementById('dashboardLogsCount');if(count)count.textContent=`Zeige ${rows.length?1:0} bis ${rows.length} von ${rows.length} Einträgen`;
  }
  /** Lädt die vollständige Protokollansicht entsprechend der Filter. */
  async loadLogs(){
    const body=document.getElementById('logsBody');if(!body||!this.has('logs.view'))return;body.innerHTML='<tr><td colspan="7" class="table-empty">Lade Logs…</td></tr>';
    try{const level=document.getElementById('logLevelFilter')?.value||'';const scope=document.getElementById('logScopeFilter')?.value||'all';const switchId=document.getElementById('logSwitchFilter')?.value||'';this.fullLogs=await this.fetchLogs(level,0,500,scope,switchId);this.renderFullLogs();}catch(e){body.innerHTML=`<tr><td colspan="7" class="table-empty">${this.escape(e.message)}</td></tr>`;}
  }
  /** Filtert und zeichnet die vollständige Protokolltabelle. */
  renderFullLogs(){const body=document.getElementById('logsBody');if(!body)return;const q=(document.getElementById('fullLogSearch')?.value||'').trim().toLowerCase();const rows=this.fullLogs.filter(x=>!q||[x.created_at,x.source,x.switch_id,x.level,x.username,x.event,x.ip_address,x.message].some(v=>String(v||'').toLowerCase().includes(q)));body.innerHTML=rows.map(x=>`<tr><td>${this.formatDate(x.created_at)}</td><td>${x.source==='switch'?`Switch: ${this.escape(x.switch_id||'Stack')}`:'CMS'}</td><td><span class="log-level ${String(x.level).toLowerCase()}">${this.escape(x.level)}</span></td><td>${this.escape(x.username||'—')}</td><td>${this.escape(x.event)}</td><td>${this.escape(x.ip_address||'—')}</td><td>${this.escape(x.message||'')}</td></tr>`).join('')||'<tr><td colspan="7" class="table-empty">Keine Logs vorhanden.</td></tr>';}

  /** Befüllt Switch-Auswahlfelder und erhält eine gültige Auswahl. */
  populateSwitchSelectors(switches=window.dashboard?.switches||[]){
    ['monitorScope','logSwitchFilter'].forEach(id=>{const select=document.getElementById(id);if(!select)return;const current=select.value;const first=id==='monitorScope'?'<option value="stack">Gesamter Stack</option>':'<option value="">Switch wählen</option>';select.innerHTML=first+switches.map(sw=>`<option value="${this.escape(sw.id)}">${this.escape(sw.name)}</option>`).join('');if([...select.options].some(o=>o.value===current))select.value=current;});
  }

  /** Loads bounded historical samples and redraws the monitoring chart. */
  async loadMonitoring(){
    const body=document.getElementById('monitorBody');if(!body||!this.has('monitoring.view'))return;
    this.populateSwitchSelectors();body.innerHTML='<tr><td colspan="8" class="table-empty">Lade Monitoring…</td></tr>';
    try{
      const id=document.getElementById('monitorScope')?.value||'stack';const hours=document.getElementById('monitorRange')?.value||24;
      const r=await fetch(`api/monitoring.php?id=${encodeURIComponent(id)}&hours=${encodeURIComponent(hours)}&_=${Date.now()}`,{cache:'no-store'});const d=await r.json();if(!r.ok)throw new Error(d.error||'Monitoring konnte nicht geladen werden.');
      const s=d.summary||{};document.getElementById('monitorOnline').textContent=`${Number(s.online||0)} / ${Number(s.switches||0)}`;document.getElementById('monitorPorts').textContent=`${Number(s.ports_up||0)} / ${Number(s.ports_total||0)}`;document.getElementById('monitorErrors').textContent=Number(s.errors||0).toLocaleString('de-DE');
      const samples=d.samples||[];body.innerHTML=samples.slice(-100).reverse().map(x=>`<tr><td>${this.formatUnix(x.sampled_at)}</td><td>${this.escape(x.switch_name)}</td><td><span class="badge ${Number(x.is_online)?'badge-success':'badge-muted'}">${Number(x.is_online)?'ONLINE':'OFFLINE'}</span></td><td>${Number(x.ports_up)}/${Number(x.ports_total)}</td><td>${this.formatBytes(x.rx_bytes)}</td><td>${this.formatBytes(x.tx_bytes)}</td><td>${Number(x.errors).toLocaleString('de-DE')}</td><td>${Number(x.response_ms)} ms</td></tr>`).join('')||'<tr><td colspan="8" class="table-empty">Noch keine Samples. Nutze „Aktualisieren“ im Dashboard.</td></tr>';
      const points=new Map();samples.forEach(x=>{const key=Math.floor(Number(x.sampled_at)/60)*60;const p=points.get(key)||{bySwitch:new Map()};p.bySwitch.set(x.switch_id,x);points.set(key,p);});const entries=[...points.entries()].sort((a,b)=>a[0]-b[0]).map(([time,p])=>[time,{online:[...p.bySwitch.values()].reduce((n,x)=>n+Number(x.is_online),0),ports:[...p.bySwitch.values()].reduce((n,x)=>n+Number(x.ports_up),0)}]);
      if(this.monitorChart)this.monitorChart.destroy();const canvas=document.getElementById('monitorChart');if(canvas){await window.loadChartLibrary();this.monitorChart=new Chart(canvas.getContext('2d'),{type:'line',data:{labels:entries.map(([t])=>new Date(t*1000).toLocaleTimeString('de-DE',{hour:'2-digit',minute:'2-digit'})),datasets:[{label:'Switches online',data:entries.map(([,p])=>p.online),borderColor:'#18d780',backgroundColor:'rgba(24,215,128,.12)',tension:.25},{label:'Ports aktiv',data:entries.map(([,p])=>p.ports),borderColor:'#38bdf8',backgroundColor:'rgba(56,189,248,.1)',tension:.25}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'#94a3b8'}}},scales:{x:{ticks:{color:'#64748b'},grid:{color:'#1e293b'}},y:{beginAtZero:true,ticks:{color:'#64748b'},grid:{color:'#1e293b'}}}}});}
    }catch(e){body.innerHTML=`<tr><td colspan="8" class="table-empty">${this.escape(e.message)}</td></tr>`;}
  }

  /** Löscht nach Bestätigung die gespeicherten Protokolle. */
  async clearLogs(){
    if(!this.isAdmin)return;
    if(!confirm('Wirklich alle System Logs dauerhaft löschen? Diese Aktion kann nicht rückgängig gemacht werden.'))return;
    const btn=document.getElementById('logsClear');this.setBusy(btn,true,'Lösche…');
    try{
      const f=new FormData();f.append('action','clear');
      const r=await fetch('api/logs.php',{method:'POST',body:f,cache:'no-store'});const d=await r.json();
      if(!r.ok||!d.success)throw new Error(d.error||'Logs konnten nicht gelöscht werden.');
      this.fullLogs=[];this.dashboardLogs=[];this.renderFullLogs();this.renderDashboardLogs();
      await this.loadLogs();await this.loadDashboardLogs();
    }catch(e){alert(e.message);}finally{this.setBusy(btn,false);}
  }

  /** Lädt Benutzerkonten und bindet deren Verwaltungsaktionen. */
  async loadUsers(){
    const body=document.getElementById('usersBody');if(!body||!this.has('users.manage'))return;body.innerHTML='<tr><td colspan="6" class="table-empty">Lade Benutzer…</td></tr>';
    try{const r=await fetch('api/users.php?action=list',{cache:'no-store'});const d=await r.json();if(!r.ok)throw new Error(d.error||'Fehler');const me=document.body.dataset.username||'';body.innerHTML=(d.users||[]).map(u=>{const protectedUser=u.username==='admin'||u.username===me;const canEdit=this.isAdmin||u.role!=='admin';const email=u.email?`${this.escape(u.email)} <span class="badge ${u.email_verified_at?'badge-success':'badge-cache'}">${u.email_verified_at?'BESTÄTIGT':'OFFEN'}</span>`:'—';return `<tr><td><strong>${this.escape(u.username)}</strong></td><td>${email}</td><td><span class="role-pill">${u.role==='admin'?'Administrator':'Benutzer'}</span></td><td><span class="badge ${Number(u.two_factor_enabled)?'badge-success':'badge-muted'}">${Number(u.two_factor_enabled)?'AKTIV':'AUS'}</span></td><td>${this.formatDate(u.created_at)}</td><td>${canEdit?`<button type="button" class="btn btn-small edit-user" data-id="${u.id}">Bearbeiten</button>`:''}${canEdit&&!protectedUser?` <button type="button" class="btn btn-small btn-danger delete-user" data-id="${u.id}">Löschen</button>`:''}${this.isAdmin&&Number(u.two_factor_enabled)?` <button type="button" class="btn btn-small reset-2fa" data-id="${u.id}">2FA zurücksetzen</button>`:''}${canEdit&&u.email&&!u.email_verified_at?` <button type="button" class="btn btn-small resend-email" data-id="${u.id}">E-Mail erneut senden</button>`:''}</td></tr>`;}).join('')||'<tr><td colspan="6" class="table-empty">Keine Benutzer vorhanden.</td></tr>';
      const byId=new Map((d.users||[]).map(u=>[String(u.id),u]));body.querySelectorAll('.edit-user').forEach(b=>b.addEventListener('click',()=>this.openUser(byId.get(b.dataset.id))));body.querySelectorAll('.delete-user').forEach(b=>b.addEventListener('click',()=>this.deleteUser(b.dataset.id)));body.querySelectorAll('.reset-2fa').forEach(b=>b.addEventListener('click',()=>this.reset2fa(b.dataset.id)));body.querySelectorAll('.resend-email').forEach(b=>b.addEventListener('click',()=>this.resendVerification(b.dataset.id)));
    }catch(e){body.innerHTML=`<tr><td colspan="6" class="table-empty">${this.escape(e.message||'Benutzerverwaltung nicht verfügbar.')}</td></tr>`;}
  }
  /** Öffnet das Benutzerformular zum Anlegen oder Bearbeiten. */
  openUser(u=null){
    if(!this.has('users.manage'))return;const modal=document.getElementById('userModal');if(!modal)return;
    document.getElementById('userModalTitle').textContent=u?'Benutzer bearbeiten':'Benutzer hinzufügen';document.getElementById('userModalSubtitle').textContent=u?'Konto, E-Mail, Rolle oder Passwort ändern.':'Neues Konto mit E-Mail-Verifikation anlegen.';document.getElementById('userId').value=u?.id||'';document.getElementById('userName').value=u?.username||'';document.getElementById('userEmail').value=u?.email||'';document.getElementById('userRole').value=u?.role||'user';document.getElementById('userRole').disabled=!this.isAdmin;document.getElementById('userPassword').value='';document.getElementById('userPassword').required=!u;document.getElementById('passwordHint').textContent=u?'(leer lassen = unverändert)':'';modal.classList.add('active');setTimeout(()=>document.getElementById('userName')?.focus(),50);
  }
  /** Übermittelt ein Benutzerformular und aktualisiert anschließend die Liste. */
  async saveUser(e){e.preventDefault();const btn=e.submitter;btn.disabled=true;const f=new FormData(e.target);f.append('action','save');try{const r=await fetch('api/users.php',{method:'POST',body:f});const d=await r.json();if(!r.ok)throw new Error(d.error||'Fehler');this.closeModal('userModal');e.target.reset();await this.loadUsers();await this.loadDashboardLogs();if(d.email_sent===false)alert('Benutzer gespeichert, aber die Verifikationsmail konnte nicht gesendet werden. Bitte SMTP konfigurieren und erneut senden.');}catch(err){alert(err.message);}finally{btn.disabled=false;}}
  /** Löscht nach Bestätigung ein Benutzerkonto. */
  async deleteUser(id){if(!confirm('Benutzer wirklich löschen?'))return;const f=new FormData();f.append('action','delete');f.append('id',id);try{const r=await fetch('api/users.php',{method:'POST',body:f});const d=await r.json();if(!r.ok)throw new Error(d.error||'Fehler');await this.loadUsers();await this.loadDashboardLogs();}catch(e){alert(e.message);}}
  /** Setzt die Zwei-Faktor-Konfiguration eines Benutzerkontos zurück. */
  async reset2fa(id){if(!confirm('2FA dieses Benutzers wirklich zurücksetzen?'))return;const f=new FormData();f.append('action','reset2fa');f.append('id',id);try{const r=await fetch('api/users.php',{method:'POST',body:f});const d=await r.json();if(!r.ok)throw new Error(d.error||'Fehler');await this.loadUsers();await this.loadDashboardLogs();}catch(e){alert(e.message);}}
  /** Versendet die E-Mail-Verifikation erneut. */
  async resendVerification(id){const f=new FormData();f.append('action','resend_verification');f.append('id',id);try{const r=await fetch('api/users.php',{method:'POST',body:f});const d=await r.json();if(!r.ok)throw new Error(d.error||'E-Mail konnte nicht gesendet werden.');alert('Verifikationsmail wurde gesendet.');}catch(e){alert(e.message);}}

  /** Loads the complete permission editor model for administrators. */
  async loadPermissions(){
    if(!this.has('permissions.manage')||!document.getElementById('permissionUsers'))return;
    try{const r=await fetch('api/permissions.php?action=list',{cache:'no-store'});const d=await r.json();if(!r.ok)throw new Error(d.error||'Rechte konnten nicht geladen werden.');this.permissionData=d;this.renderPermissions();}
    catch(e){document.getElementById('permissionUsers').innerHTML=`<div class="table-empty">${this.escape(e.message)}</div>`;}
  }
  /** Zeichnet Benutzer- und Gruppenlisten der Rechteverwaltung. */
  renderPermissions(){
    const d=this.permissionData;if(!d)return;const users=document.getElementById('permissionUsers'),groups=document.getElementById('permissionGroups');
    users.innerHTML=d.users.map(u=>`<div class="permission-row"><div><strong>${this.escape(u.username)}</strong><small>${u.role==='admin'?'Administrator – Vollzugriff':`${Number(u.effective_permissions?.length||0)} wirksame Rechte`}</small></div>${u.role==='admin'?'<span class="badge badge-success">VOLLER ZUGRIFF</span>':`<button class="btn btn-small edit-permissions" data-id="${u.id}">Rechte bearbeiten</button>`}</div>`).join('');
    groups.innerHTML=d.groups.map(g=>{const count=d.members.filter(m=>Number(m.group_id)===Number(g.id)).length;return `<div class="permission-row"><div><strong>${this.escape(g.name)}</strong><small>${this.escape(g.description||'Keine Beschreibung')} · ${count} Mitglied${count===1?'':'er'}</small></div><div><button class="btn btn-small edit-group" data-id="${g.id}">Bearbeiten</button> <button class="btn btn-small btn-danger delete-group" data-id="${g.id}">Löschen</button></div></div>`;}).join('')||'<div class="table-empty">Noch keine Gruppen vorhanden.</div>';
    users.querySelectorAll('.edit-permissions').forEach(b=>b.addEventListener('click',()=>this.openUserPermissions(Number(b.dataset.id))));groups.querySelectorAll('.edit-group').forEach(b=>b.addEventListener('click',()=>this.openGroup(Number(b.dataset.id))));groups.querySelectorAll('.delete-group').forEach(b=>b.addEventListener('click',()=>this.deleteGroup(Number(b.dataset.id))));
  }
  /** Erzeugt eine Liste auswählbarer und gegebenenfalls geerbter Einträge. */
  checkboxList(container,items,selected,prefix,locked=[]){
    const selectedSet=new Set(selected.map(String)),lockedSet=new Set(locked.map(String));container.innerHTML=items.map(item=>{const inherited=lockedSet.has(String(item.value));return `<label class="check-item ${inherited?'inherited':''}"><input type="checkbox" data-value="${this.escape(item.value)}" ${selectedSet.has(String(item.value))||inherited?'checked':''} ${inherited?'disabled':''}><span>${this.escape(item.label)}${inherited?'<small>Geerbt / Sammelrecht</small>':''}</span></label>`;}).join('')||'<span class="muted">Keine Einträge vorhanden.</span>';container.dataset.prefix=prefix;
  }
  /** Liest die Werte aller aktiv ausgewählten Kontrollfelder aus. */
  checkedValues(containerId){return [...document.querySelectorAll(`#${containerId} input:checked:not(:disabled)`)].map(input=>input.dataset.value);}
  /** Öffnet die Rechte- und Gruppenzuweisung eines Benutzers. */
  openUserPermissions(userId){
    const d=this.permissionData,u=d?.users.find(x=>Number(x.id)===userId);if(!u)return;document.getElementById('permissionUserId').value=userId;document.getElementById('permissionModalTitle').textContent=`Rechte für ${u.username}`;
    const direct=d.userPermissions.filter(x=>Number(x.user_id)===userId&&Number(x.allowed)&&d.catalog[x.permission_code]).map(x=>x.permission_code);const effective=u.effective_permissions||[];const configured=!!u.permissions_configured;const memberGroups=d.members.filter(x=>Number(x.user_id)===userId).map(x=>x.group_id);let groupCodes=d.groupPermissions.filter(x=>memberGroups.some(id=>Number(id)===Number(x.group_id))&&Number(x.allowed)).map(x=>x.permission_code);if(groupCodes.includes('switch.control'))groupCodes=[...new Set([...groupCodes,'switch.reboot','switch.backup','switch.restore','switch.port.manage','switch.lag.manage','switch.vlan.manage'])];const derived=direct.includes('switch.control')?['switch.reboot','switch.backup','switch.restore','switch.port.manage','switch.lag.manage','switch.vlan.manage']:[];const inherited=effective.filter(code=>(groupCodes.includes(code)||derived.includes(code))&&!direct.includes(code));const selected=configured?direct:effective.filter(code=>!inherited.includes(code));const switches=d.userSwitches.filter(x=>Number(x.user_id)===userId).map(x=>x.switch_id);
    this.checkboxList(document.getElementById('permissionChecks'),Object.entries(d.catalog).map(([value,label])=>({value,label})),selected,'permission',inherited);this.checkboxList(document.getElementById('permissionGroupChecks'),d.groups.map(x=>({value:x.id,label:x.name})),memberGroups,'group');this.checkboxList(document.getElementById('permissionSwitchChecks'),d.switches.map(x=>({value:x.id,label:x.name})),switches,'switch');document.getElementById('permissionModal').classList.add('active');
  }
  /** Speichert die vollständigen Rechte- und Switch-Zuweisungen eines Benutzers. */
  async saveUserPermissions(e){e.preventDefault();const f=new FormData();f.append('action','save_user');f.append('user_id',document.getElementById('permissionUserId').value);f.append('permissions',JSON.stringify(this.checkedValues('permissionChecks')));f.append('groups',JSON.stringify(this.checkedValues('permissionGroupChecks')));f.append('switches',JSON.stringify(this.checkedValues('permissionSwitchChecks')));const btn=e.submitter;this.setBusy(btn,true,'Speichere…');try{const r=await fetch('api/permissions.php',{method:'POST',body:f});const d=await r.json();if(!r.ok)throw new Error(d.error||'Rechte konnten nicht gespeichert werden.');this.closeModal('permissionModal');await this.loadPermissions();}catch(error){alert(error.message);}finally{this.setBusy(btn,false);}}
  /** Öffnet das Formular zum Anlegen oder Bearbeiten einer Gruppe. */
  openGroup(groupId=0){
    const d=this.permissionData;if(!d&&groupId){return;}const group=d?.groups.find(x=>Number(x.id)===groupId);document.getElementById('groupId').value=groupId||'';document.getElementById('groupName').value=group?.name||'';document.getElementById('groupDescription').value=group?.description||'';document.getElementById('groupModalTitle').textContent=group?'Gruppe bearbeiten':'Gruppe hinzufügen';const perms=group?d.groupPermissions.filter(x=>Number(x.group_id)===groupId&&Number(x.allowed)).map(x=>x.permission_code):[];const switches=group?d.groupSwitches.filter(x=>Number(x.group_id)===groupId).map(x=>x.switch_id):[];const members=group?d.members.filter(x=>Number(x.group_id)===groupId).map(x=>x.user_id):[];const catalog=d?.catalog||{};this.checkboxList(document.getElementById('groupUserChecks'),(d?.users||[]).filter(x=>x.role!=='admin').map(x=>({value:x.id,label:x.username})),members,'member');this.checkboxList(document.getElementById('groupPermissionChecks'),Object.entries(catalog).map(([value,label])=>({value,label})),perms,'permission');this.checkboxList(document.getElementById('groupSwitchChecks'),(d?.switches||[]).map(x=>({value:x.id,label:x.name})),switches,'switch');document.getElementById('groupModal').classList.add('active');
  }
  /** Speichert Gruppenstammdaten, Mitglieder, Rechte und Switch-Zugriffe. */
  async saveGroup(e){e.preventDefault();const f=new FormData();f.append('action','save_group');f.append('group_id',document.getElementById('groupId').value);f.append('name',document.getElementById('groupName').value);f.append('description',document.getElementById('groupDescription').value);f.append('members',JSON.stringify(this.checkedValues('groupUserChecks')));f.append('permissions',JSON.stringify(this.checkedValues('groupPermissionChecks')));f.append('switches',JSON.stringify(this.checkedValues('groupSwitchChecks')));const btn=e.submitter;this.setBusy(btn,true,'Speichere…');try{const r=await fetch('api/permissions.php',{method:'POST',body:f});const d=await r.json();if(!r.ok)throw new Error(d.error||'Gruppe konnte nicht gespeichert werden.');this.closeModal('groupModal');await this.loadPermissions();}catch(error){alert(error.message);}finally{this.setBusy(btn,false);}}
  /** Löscht nach Bestätigung eine Gruppe, nicht jedoch deren Benutzer. */
  async deleteGroup(groupId){if(!confirm('Gruppe wirklich löschen? Benutzerkonten bleiben bestehen.'))return;const f=new FormData();f.append('action','delete_group');f.append('group_id',groupId);try{const r=await fetch('api/permissions.php',{method:'POST',body:f});const d=await r.json();if(!r.ok)throw new Error(d.error||'Gruppe konnte nicht gelöscht werden.');await this.loadPermissions();}catch(error){alert(error.message);}}
  /** Loads redacted alert settings, state transitions and worker health. */
  async loadAlerts(){
    const body=document.getElementById('alertStatesBody');if(!body||!this.has('alerts.manage'))return;body.innerHTML='<tr><td colspan="5" class="table-empty">Lade Alert-Einstellungen…</td></tr>';
    try{
      const r=await fetch('api/alerts.php',{cache:'no-store'}),d=await r.json();if(!r.ok)throw new Error(d.error||'Alerts konnten nicht geladen werden.');const s=d.settings||{};
      document.getElementById('alertEnabled').checked=Number(s.enabled)===1;document.getElementById('alertEmailEnabled').checked=Number(s.email_enabled)===1;document.getElementById('alertRecipients').value=s.email_recipients||'';document.getElementById('alertWebhookEnabled').checked=Number(s.webhook_enabled)===1;document.getElementById('alertWebhookUrl').value=s.webhook_url||'';document.getElementById('alertRecovery').checked=Number(s.recovery_enabled)===1;document.getElementById('alertThreshold').value=s.failure_threshold||2;document.getElementById('alertCooldown').value=s.cooldown_minutes||30;document.getElementById('alertSecretHint').textContent=s.webhook_secret_configured?'(vorhanden)':'';
      const smtpValues={smtpHost:s.smtp_host||'',smtpPort:s.smtp_port||587,smtpSecure:s.smtp_secure??'tls',smtpUsername:s.smtp_username||'',smtpFrom:s.smtp_from||''};Object.entries(smtpValues).forEach(([id,value])=>{const el=document.getElementById(id);if(el)el.value=value;});document.getElementById('smtpPassword').value='';document.getElementById('smtpPasswordHint').textContent=s.smtp_password_configured?'(vorhanden)':'';
      const job=d.monitor_job||null,jobStatus=document.getElementById('monitorJobStatus');if(jobStatus){const enabled=!!job&&!!Number(job.enabled),completed=!!job&&Number(job.completed_at)>0,running=!!job&&(job.message==='Läuft'||Number(job.started_at)>Number(job.completed_at||0)),fresh=!!job&&!!job.fresh,healthy=enabled&&completed&&fresh&&!!Number(job.success);jobStatus.textContent=!enabled?'Hintergrund-Worker: deaktiviert':running?`Hintergrund-Worker: LÄUFT · seit ${this.formatUnix(job.started_at)} · ${Number(job.processed_count||0)} Switches geprüft`:!completed?'Hintergrund-Worker: noch kein Lauf':`Hintergrund-Worker: ${healthy?'OK':fresh?'FEHLER':'VERALTET'} · ${this.formatUnix(job.completed_at)} · ${Number(job.processed_count||0)} Switches, ${Number(job.error_count||0)} nicht erreichbar`;jobStatus.classList.toggle('worker-error',enabled&&!running&&!healthy);}
      body.innerHTML=(d.states||[]).map(x=>`<tr><td><strong>${this.escape(x.name)}</strong><br><small>${this.escape(x.host)}</small></td><td><span class="badge ${Number(x.last_status)?'badge-success':'badge-muted'}">${x.last_status===null?'UNBEKANNT':(Number(x.last_status)?'ONLINE':'OFFLINE')}</span></td><td>${Number(x.consecutive_failures||0)}</td><td>${Number(x.down_alert_sent)?'Ja':'Nein'}</td><td>${x.last_change_at?this.formatUnix(x.last_change_at):'—'}</td></tr>`).join('')||'<tr><td colspan="5" class="table-empty">Noch kein Switch wurde durch den Alert-Poller geprüft.</td></tr>';
    }
    catch(error){body.innerHTML=`<tr><td colspan="5" class="table-empty">${this.escape(error.message)}</td></tr>`;}
  }
  /** Speichert oder testet die eingegebene Alarmkonfiguration. */
  async saveAlerts(event,action='save'){
    event.preventDefault();const button=action==='test'?event.currentTarget:event.submitter;this.setBusy(button,true,action==='test'?'Sende Test…':'Speichere…');const f=new FormData();f.append('action',action);f.append('enabled',document.getElementById('alertEnabled').checked?'1':'0');f.append('email_enabled',document.getElementById('alertEmailEnabled').checked?'1':'0');f.append('email_recipients',document.getElementById('alertRecipients').value);f.append('webhook_enabled',document.getElementById('alertWebhookEnabled').checked?'1':'0');f.append('webhook_url',document.getElementById('alertWebhookUrl').value);f.append('webhook_secret',document.getElementById('alertWebhookSecret').value);f.append('recovery_enabled',document.getElementById('alertRecovery').checked?'1':'0');f.append('failure_threshold',document.getElementById('alertThreshold').value);f.append('cooldown_minutes',document.getElementById('alertCooldown').value);[['smtp_host','smtpHost'],['smtp_port','smtpPort'],['smtp_secure','smtpSecure'],['smtp_username','smtpUsername'],['smtp_password','smtpPassword'],['smtp_from','smtpFrom']].forEach(([key,id])=>f.append(key,document.getElementById(id)?.value||''));
    try{const r=await fetch('api/alerts.php',{method:'POST',body:f}),d=await r.json();if(!r.ok)throw new Error(d.error||'Alert-Einstellungen konnten nicht gespeichert werden.');document.getElementById('alertWebhookSecret').value='';document.getElementById('smtpPassword').value='';alert(action==='test'?'Test-Alert wurde zugestellt.':'Alert-Einstellungen wurden gespeichert.');await this.loadAlerts();}catch(error){alert(error.message);}finally{this.setBusy(button,false);}
  }
  /** Loads redacted OIDC settings; existing secrets never enter the DOM. */
  async loadOidc(){
    if(!this.has('sso.manage')||!document.getElementById('oidcForm'))return;
    try{
      const r=await fetch('api/oidc.php',{cache:'no-store'}),d=await r.json();if(!r.ok)throw new Error(d.error||'OpenID-Connect-Einstellungen konnten nicht geladen werden.');const s=d.settings||{};
      const values={oidcName:s.provider_name||'OpenID Connect',oidcBaseUrl:s.public_base_url||'',oidcDiscoveryUrl:s.discovery_url||'',oidcCallbackUrl:s.callback_url||'',oidcClientId:s.client_id||'',oidcScopes:s.scopes||'openid email profile',oidcClientAuth:s.client_auth_method||'post',oidcDomains:s.allowed_domains||'',oidcEmailClaim:s.email_claim||'email',oidcUsernameClaim:s.username_claim||'preferred_username',oidcNameClaim:s.name_claim||'name',oidcVerifiedClaim:s.email_verified_claim||'email_verified'};
      Object.entries(values).forEach(([id,value])=>{const el=document.getElementById(id);if(el)el.value=value;});
      const checks={oidcEnabled:s.enabled,oidcPublicClient:s.public_client,oidcAutoCreate:s.auto_create,oidcTrustEmail:s.trust_provider_email,oidcTlsVerify:s.tls_verify,oidcAllowHttp:s.allow_http};Object.entries(checks).forEach(([id,value])=>{const el=document.getElementById(id);if(el)el.checked=Number(value)===1;});
      document.getElementById('oidcSecretHint').textContent=s.client_secret_configured?'(vorhanden)':'';document.getElementById('oidcClientSecret').value='';
    }catch(error){const result=document.getElementById('oidcTestResult');if(result){result.textContent=error.message;result.className='notice error';}}
  }
  /** Sammelt die OIDC-Formularwerte für Speichern oder Verbindungstest. */
  oidcFormData(action){
    const f=new FormData();f.append('action',action);const value=(id)=>document.getElementById(id)?.value||'',checked=(id)=>document.getElementById(id)?.checked?'1':'0';
    [['provider_name','oidcName'],['public_base_url','oidcBaseUrl'],['discovery_url','oidcDiscoveryUrl'],['client_id','oidcClientId'],['client_secret','oidcClientSecret'],['scopes','oidcScopes'],['client_auth_method','oidcClientAuth'],['allowed_domains','oidcDomains'],['email_claim','oidcEmailClaim'],['username_claim','oidcUsernameClaim'],['name_claim','oidcNameClaim'],['email_verified_claim','oidcVerifiedClaim']].forEach(([key,id])=>f.append(key,value(id)));
    [['enabled','oidcEnabled'],['public_client','oidcPublicClient'],['auto_create','oidcAutoCreate'],['trust_provider_email','oidcTrustEmail'],['tls_verify','oidcTlsVerify'],['allow_http','oidcAllowHttp']].forEach(([key,id])=>f.append(key,checked(id)));return f;
  }
  /** Speichert oder testet die OpenID-Connect-Konfiguration. */
  async saveOidc(event,action='save'){
    event.preventDefault();const button=action==='test'?event.currentTarget:event.submitter,result=document.getElementById('oidcTestResult');this.setBusy(button,true,action==='test'?'Teste Discovery…':'Speichere…');if(result)result.className='notice hidden';
    try{const r=await fetch('api/oidc.php',{method:'POST',body:this.oidcFormData(action)}),d=await r.json();if(!r.ok)throw new Error(d.error||'OpenID Connect konnte nicht gespeichert werden.');if(result){result.textContent=action==='test'?`Discovery erfolgreich. Issuer: ${d.test?.issuer||'—'}`:'OpenID-Connect-Einstellungen wurden gespeichert.';result.className='notice success';}await this.loadOidc();}catch(error){if(result){result.textContent=error.message;result.className='notice error';}else alert(error.message);}finally{this.setBusy(button,false);}
  }
  /** Formatiert eine Byte-Anzahl als gut lesbare Größenangabe. */
  formatBytes(v){const b=Number(v)||0;if(b>=1073741824)return(b/1073741824).toFixed(2)+' GB';if(b>=1048576)return(b/1048576).toFixed(2)+' MB';if(b>=1024)return(b/1024).toFixed(2)+' KB';return b+' B';}
  /** Formatiert einen Unix-Zeitstempel für die deutsche Oberfläche. */
  formatUnix(v){const d=new Date(Number(v)*1000);return Number.isNaN(d.getTime())?'—':d.toLocaleString('de-DE',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit'});}
  /** Formatiert einen Datenbank-Zeitwert für die deutsche Oberfläche. */
  formatDate(v){if(!v)return'—';const d=new Date(String(v).replace(' ','T')+'Z');if(Number.isNaN(d.getTime()))return this.escape(v);return d.toLocaleString('de-DE',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit'});}
  /** Escapes values before they are interpolated into generated HTML. */
  escape(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));}
}
window.AppShell=new AppShell();
