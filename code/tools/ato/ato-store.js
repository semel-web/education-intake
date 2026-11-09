// Shared data layer — WordPress backend (ATO Portal plugin).
// Public API used by the intake, console and approval pages.
// Sign-in is the WordPress session cookie (UCLA Logon / SSO if the site has it, or a WP account).
// The tools must be served from the same domain as WordPress (e.g. /tools/ato/).
(function(){
  const CFG = Object.assign({ root: '' }, window.ATO_WP || {});
  const AJAX = CFG.root + '/wp-admin/admin-ajax.php';
  const REST = CFG.root + '/wp-json/ato/v1/';
  const enc = encodeURIComponent;

  let sess = { user:null, nonce:'', loginNonce:'' };
  let booted = null;

  async function ajax(action, body){
    const fd = new URLSearchParams(Object.assign({ action }, body || {}));
    let r;
    try{ r = await fetch(AJAX, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: fd.toString() }); }
    catch(e){ throw new Error('Can\u2019t reach the ATO portal. Check your connection and try again.'); }
    const txt = await r.text(); let j = null; try{ j = JSON.parse(txt); }catch(e){}
    if(!j) throw new Error('The ATO portal isn\u2019t responding (' + r.status + '). The plugin may be inactive.');
    if(!j.success) throw new Error((j.data && j.data.message) || 'Request failed.');
    return j.data;
  }

  async function bootstrap(){
    const d = await ajax('ato_session');
    const prevId = sess.user && sess.user.id;
    sess = { user: d.user, nonce: d.nonce || '', loginNonce: d.login_nonce || '' };
    Store.caps = Object.assign({ pending:false, seed:true }, d.caps || {});
    Store._loginUrl = d.login_url; Store._logoutUrl = d.logout_url;
    Store._staff = undefined;
    return prevId !== (d.user && d.user.id);
  }
  function boot(){
    booted = bootstrap().then(()=>Store._emit()).catch(e=>{ Store.caps = { password:false, signup:false, sso:false, pending:false, error:e.message }; Store._emit(); });
    return booted;
  }

  async function req(path, opts, retried){
    if(booted) await booted;
    const headers = Object.assign({ 'Content-Type':'application/json', 'X-WP-Nonce': sess.nonce }, (opts && opts.headers) || {});
    const r = await fetch(REST + path, Object.assign({ credentials:'same-origin', cache:'no-store' }, opts, { headers }));
    const txt = await r.text(); let j = null; try{ j = txt ? JSON.parse(txt) : null; }catch(e){}
    if(r.status === 401 || r.status === 403){
      const code = j && j.code;
      if(!retried && (code === 'rest_cookie_invalid_nonce' || code === 'ato_signed_out')){
        const changed = await bootstrap(); if(changed) Store._emit();
        if(sess.user) return req(path, opts, true);
      }
      if(!sess.user || code === 'ato_signed_out'){ Store._emit(); throw new Error('Your session expired \u2014 sign in again.'); }
      throw new Error((j && j.message) || 'You don\u2019t have access to that.');
    }
    if(!r.ok) throw new Error((j && j.message) || ('Server error ' + r.status));
    return j;
  }

  async function pub(path, opts){
    let r; try{ r = await fetch(REST + path, Object.assign({ credentials:'omit', cache:'no-store', headers:{ 'Content-Type':'application/json' } }, opts || {})); }
    catch(e){ throw new Error('Can\u2019t reach the ATO portal. Check your connection and try again.'); }
    const txt = await r.text(); let j = null; try{ j = txt ? JSON.parse(txt) : null; }catch(e){}
    if(!r.ok) throw new Error((j && j.message) || ('Server error ' + r.status));
    return j;
  }

  // Re-check the WordPress session when the tab regains focus (cookie may have expired or been renewed).
  let lastCheck = 0;
  window.addEventListener('focus', ()=>{ if(Date.now() - lastCheck < 30000) return; lastCheck = Date.now();
    bootstrap().then(changed=>{ if(changed) Store._emit(); }).catch(()=>{}); });

  const Store = {
    backend: 'wordpress',
    caps: { password:false, signup:false, sso:false, pending:true },
    _subs: [],
    onAuth(fn){ this._subs.push(fn); return () => { this._subs = this._subs.filter(x=>x!==fn); }; },
    _emit(){ this._subs.forEach(fn => { try{ fn(this.user()); }catch(e){} }); },

    // ---- auth ----
    user(){ return sess.user ? Object.assign({}, sess.user) : null; },
    ssoSignIn(){
      // Identity providers refuse to load inside an iframe, so leave the frame and come back to this page.
      let top; try{ top = window.top.location.href; }catch(e){ top = location.href; }
      const url = this._loginUrl + (this._loginUrl.indexOf('?') > -1 ? '&' : '?') + 'redirect_to=' + enc(top);
      try{ window.top.location.href = url; }catch(e){ location.href = url; }
    },
    async signIn(email, password){
      await ajax('ato_login', { email: email.trim(), password, _n: sess.loginNonce });
      await bootstrap(); this._emit(); return this.user();
    },
    async signUp(email, password, full_name, department){
      await ajax('ato_signup', { email: email.trim(), password, full_name: full_name||'', department: department||'', _n: sess.loginNonce });
      await bootstrap(); this._emit(); return this.user() ? { user: this.user() } : { confirm:true };
    },
    async resetPassword(email){ await ajax('ato_reset', { email: email.trim(), _n: sess.loginNonce }); },
    async signOut(){
      try{ await ajax('ato_logout', { _n: sess.nonce }); }catch(e){}
      await bootstrap().catch(()=>{}); this._emit();
    },
    async staffRecord(){
      if(!this.user()) return null;
      if(this._staff !== undefined) return this._staff;
      this._staff = (await req('staff/me', {})) || null; return this._staff;
    },
    async listStaff(){ return (await req('staff', {})) || []; },

    // ---- office-managed content ----
    async getConfig(){ return req('config', {}); },

    // ---- packets ----
    async savePacket(id, bundle){ return req('packets/' + enc(id), { method:'POST', body: JSON.stringify({ bundle }) }); },
    async listPackets(){ return (await req('packets', {})) || []; },
    async listMine(){ if(!this.user()) return []; return (await req('packets?mine=1', {})) || []; },

    async listUpdates(ids){ return (await req('updates' + (ids && ids.length ? '?ids=' + ids.map(enc).join(',') : ''), {})) || []; },
    async setUpdate(packet_id, patch){ return req('updates/' + enc(packet_id), { method:'POST', body: JSON.stringify(patch || {}) }); },

    async listEvents(ids){ return (await req('events' + (ids && ids.length ? '?ids=' + ids.map(enc).join(',') : ''), {})) || []; },
    async addEvent(ev){ return req('events', { method:'POST', body: JSON.stringify(ev) }); },

    // ---- approval route ----
    async listSignatures(ids){ return (await req('signatures' + (ids && ids.length ? '?ids=' + ids.map(enc).join(',') : ''), {})) || []; },
    async signatureAction(id, body){ return req('signatures/' + enc(id), { method:'POST', body: JSON.stringify(body || {}) }); },
    // Approval page: no sign-in, the token is the credential. Sent without cookies or nonce.
    async getApproval(t){ return pub('approval?t=' + enc(t)); },
    async submitApproval(t, decision, name, note){ return pub('approval', { method:'POST', body: JSON.stringify({ t, decision, name, note }) }); },

    // ---- roster (wp-admin → ATO Portal → Roster & reminders) ----
    async getRoster(){ return req('roster', {}); },

    async resetAll(){ return req('reset', { method:'POST', body:'{}' }); }
  };
  window.ATOStore = Store;
  Store.ready = boot();
})();
