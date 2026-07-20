/**
 * deploy-progress.js
 *
 * Admin UI for the live deploy progress modal.
 *
 * - Clicking "Deploy Products" opens a modal showing live progress.
 * - Navigating away and returning resumes polling automatically (runId
 *   is persisted in localStorage so the modal reopens on page load if
 *   a deploy is still in progress or just finished).
 * - The × close button is locked while deploying and unlocked on finish.
 *
 * Localised data (via wp_localize_script):
 *   window.cbsDeployData = { restBase: '...', nonce: '...' };
 */

( function () {
  'use strict';

  // ── Configuration ──────────────────────────────────────────────────────────

  const POLL_INTERVAL_MS  = 2000;
  const STUCK_TIMEOUT_MS  = 180 * 1000;  // 3 min of frozen updatedAt → stuck
  const LS_KEY            = 'cbs_deploy_run_id';
  const REST_BASE         = ( window.cbsDeployData && window.cbsDeployData.restBase )
                              ? window.cbsDeployData.restBase
                              : '/wp-json/northstaronlineordering/v1';
  const NONCE             = ( window.cbsDeployData && window.cbsDeployData.nonce )
                              ? window.cbsDeployData.nonce
                              : '';

  // ── State ──────────────────────────────────────────────────────────────────

  let runId              = null;
  let pollTimer          = null;
  let lastStatus         = null;
  let seenSiteNames      = [];
  let lastUpdatedAt          = null;  // last updatedAt value seen while running
  let lastUpdatedAtTimestamp = 0;     // wall-clock ms when lastUpdatedAt last changed

  // ── DOM refs ───────────────────────────────────────────────────────────────

  const deployBtn      = document.getElementById( 'cbs-deploy-btn' );
  const deployMediaBtn = document.getElementById( 'cbs-deploy-media-btn' );
  const cancelBtn      = document.getElementById( 'cbs-cancel-btn' );
  const queueNotice    = document.getElementById( 'cbs-deploy-queue-notice' );
  const modal        = document.getElementById( 'cbs-deploy-modal' );
  const backdrop   = document.getElementById( 'cbs-deploy-modal-backdrop' );
  const modalClose = document.getElementById( 'cbs-modal-close' );
  const progressBar = document.getElementById( 'cbs-progress-bar' );
  const stepEl     = document.getElementById( 'cbs-deploy-step' );
  const siteEl     = document.getElementById( 'cbs-deploy-site' );
  const countsEl   = document.getElementById( 'cbs-deploy-counts' );
  const resultEl   = document.getElementById( 'cbs-deploy-result' );

  if ( ! deployBtn ) return;  // guard: only run when UI is present

  // ── Event listeners ────────────────────────────────────────────────────────

  deployBtn.addEventListener( 'click', function () { handleDeployClick( true ); } );
  if ( deployMediaBtn ) deployMediaBtn.addEventListener( 'click', function () { handleDeployClick( false ); } );
  if ( cancelBtn )  cancelBtn.addEventListener( 'click', handleCancelClick );
  if ( modalClose ) modalClose.addEventListener( 'click', closeModal );
  if ( backdrop )   backdrop.addEventListener( 'click', function () {
    if ( modalClose && ! modalClose.disabled ) closeModal();
  } );

  // ── Resume on page load ────────────────────────────────────────────────────

  const savedRunId = localStorage.getItem( LS_KEY );
  if ( savedRunId ) resumeDeploy( savedRunId );

  async function resumeDeploy( savedRunId ) {
    try {
      const res  = await apiFetch( REST_BASE + '/deploy/progress?runId=' + encodeURIComponent( savedRunId ) );
      const data = await res.json();

      if ( ! res.ok ) {
        localStorage.removeItem( LS_KEY );
        return;
      }

      // A stale run means the background process crashed — silently clear it.
      if ( data.status === 'stale' ) {
        localStorage.removeItem( LS_KEY );
        return;
      }

      if ( data.status === 'running' || data.status === 'queued' ) {
        // If no progress update in 5 minutes the background process is dead
        // (server stop, PHP crash, etc.) — don't reopen the modal.
        const STALE_MS  = 5 * 60 * 1000;
        const updatedAt = data.updatedAt ? new Date( data.updatedAt ).getTime() : 0;
        if ( Date.now() - updatedAt > STALE_MS ) {
          localStorage.removeItem( LS_KEY );
          return;
        }
        runId      = savedRunId;
        lastStatus = data.status;
        setDeployBtnsDisabled( true );
        openModal( /* resetContent */ false );
        if ( cancelBtn ) cancelBtn.style.display = 'inline-block';
        renderProgress( data );
        schedulePoll( POLL_INTERVAL_MS );
      } else if ( data.nextRunId ) {
        // Finished while away, but a queued deploy fired — show attributed
        // transition message and re-attach to the next run.
        runId = savedRunId;
        openModal( /* resetContent */ false );
        handoffToNextRun( data );
      } else {
        // Finished while away — show result.
        runId = savedRunId;
        openModal( /* resetContent */ false );
        finaliseDeploy( data );
      }
    } catch ( err ) {
      localStorage.removeItem( LS_KEY );
    }
  }

  // ── Modal helpers ──────────────────────────────────────────────────────────

  function openModal( reset ) {
    if ( ! modal ) return;
    if ( reset !== false ) {
      seenSiteNames = [];
      if ( modalClose ) modalClose.disabled = true;
      if ( resultEl )  { resultEl.style.display = 'none'; resultEl.innerHTML = ''; }
      setPercent( 0 );
      setStep( '' );
      if ( siteEl )   siteEl.innerHTML = '';
      if ( countsEl ) countsEl.innerHTML = '';
      if ( cancelBtn ) {
        delete cancelBtn.dataset.mode;
        cancelBtn.textContent = 'Cancel';
        cancelBtn.className   = 'btn btn-sm btn-warning';
      }
    }
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    if ( ! modal ) return;
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  // ── Start deploy ───────────────────────────────────────────────────────────

  function setDeployBtnsDisabled( disabled ) {
    deployBtn.disabled = disabled;
    if ( deployMediaBtn ) deployMediaBtn.disabled = disabled;
  }

  async function handleDeployClick( skipImages ) {
    setDeployBtnsDisabled( true );
    openModal();
    if ( cancelBtn ) cancelBtn.style.display = 'inline-block';
    setStep( 'Starting deploy...' );

    try {
      const res  = await apiFetch( REST_BASE + '/deploy/start', {
        method: 'POST',
        body:   JSON.stringify( { skip_images: skipImages } ),
      } );
      const data = await res.json();

      if ( data.status === 'queued' ) {
        // Another deploy is running — ours is queued and will fire automatically.
        // Poll the ACTIVE run (activeRunId) so the modal shows live progress instead
        // of freezing on "Starting deploy...". data.runId does not exist in this
        // response — reading it would leave runId as undefined and break polling.
        showQueueNotice( 'Deploy queued — your deploy will run automatically after the current one, even if it fails.' );
        if ( data.activeRunId ) {
          setStep( 'Queued — tracking active deploy...' );
          runId = data.activeRunId;
          localStorage.setItem( LS_KEY, runId );
          schedulePoll( POLL_INTERVAL_MS );
        } else {
          setStep( 'Queued — no active deploy found to track.' );
          if ( modalClose ) modalClose.disabled = false;
        }
        return;
      }

      if ( ! res.ok || data.status === 'locked' ) {
        showResult( false, data.message || 'Deploy is already running (runId: ' + ( data.activeRunId || 'unknown' ) + ').' );
        resetUi();
        return;
      }

      runId = data.runId;
      localStorage.setItem( LS_KEY, runId );

      // If the last finished deploy was not successful, notify the user before
      // progress polling starts so they have context on the environment state.
      if ( data.lastRun && data.lastRun.status && data.lastRun.status !== 'completed' ) {
        var prevLabel = data.lastRun.status === 'failed'          ? 'failed'
                      : data.lastRun.status === 'stale'           ? 'did not complete'
                      : data.lastRun.status === 'partial_success' ? 'partially completed'
                      : data.lastRun.status;
        showResult( false, 'Previous save products by ' + ( data.lastRun.startedBy || 'system' ) + ' ' + prevLabel + '. Starting your deploy…', 'warning' );
      }

      setStep( 'Deploy queued — waiting for background process...' );
      schedulePoll( POLL_INTERVAL_MS );

    } catch ( err ) {
      showResult( false, 'Failed to start deploy: ' + err.message );
      resetUi();
    }
  }

  // ── Cancel ─────────────────────────────────────────────────────────────────

  async function handleCancelClick() {
    if ( cancelBtn && cancelBtn.dataset.mode === 'done' ) {
      closeModal();
      return;
    }
    if ( ! runId ) return;
    cancelBtn.disabled = true;
    setStep( 'Sending cancel request...' );
    try {
      await apiFetch( REST_BASE + '/deploy/cancel', {
        method: 'POST',
        body: JSON.stringify( { runId } ),
      } );
    } catch ( err ) {
      // Non-fatal — the run will detect the flag on next batch.
    }
  }

  // ── Polling ────────────────────────────────────────────────────────────────

  function schedulePoll( delay ) {
    clearTimeout( pollTimer );
    pollTimer = setTimeout( poll, delay );
  }

  async function poll() {
    if ( ! runId ) return;

    try {
      const res  = await apiFetch( REST_BASE + '/deploy/progress?runId=' + encodeURIComponent( runId ) );
      const data = await res.json();

      if ( ! res.ok ) {
        if ( res.status === 404 && lastStatus === 'running' ) {
          schedulePoll( POLL_INTERVAL_MS * 2 );
        } else {
          showResult( false, data.message || 'Could not read deploy progress.' );
          resetUi();
        }
        return;
      }

      lastStatus = data.status;
      renderProgress( data );

      if ( data.status === 'running' || data.status === 'queued' ) {
        // Track updatedAt to detect a frozen background process.
        if ( data.status === 'running' ) {
          if ( data.updatedAt !== lastUpdatedAt ) {
            lastUpdatedAt          = data.updatedAt;
            lastUpdatedAtTimestamp = Date.now();
          } else if ( lastUpdatedAtTimestamp > 0 &&
                      Date.now() - lastUpdatedAtTimestamp > STUCK_TIMEOUT_MS ) {
            // updatedAt has not changed in 3 minutes — background process is dead.
            clearTimeout( pollTimer );
            localStorage.removeItem( LS_KEY );
            runId                  = null;
            lastStatus             = null;
            lastUpdatedAt          = null;
            lastUpdatedAtTimestamp = 0;
            setDeployBtnsDisabled( false );
            if ( modalClose ) modalClose.disabled = false;
            if ( cancelBtn ) {
              cancelBtn.dataset.mode = 'done';
              cancelBtn.textContent  = 'Dismiss';
              cancelBtn.className    = 'btn btn-sm btn-warning';
              cancelBtn.disabled     = false;
            }
            showResult( false, 'Deploy appears stuck — no progress in 3 minutes. Please close and try again.' );
            return;
          }
        }
        schedulePoll( POLL_INTERVAL_MS );
      } else if ( data.nextRunId ) {
        clearTimeout( pollTimer );
        handoffToNextRun( data );
      } else {
        clearTimeout( pollTimer );
        finaliseDeploy( data );
      }

    } catch ( err ) {
      // Network hiccup — keep polling.
      schedulePoll( POLL_INTERVAL_MS * 2 );
    }
  }

  // ── Render progress ────────────────────────────────────────────────────────

  function renderProgress( data ) {
    setPercent( data.percent ?? 0 );
    setStep( data.currentStep || '' );

    if ( siteEl ) {
      const siteName = data.currentSiteName || data.currentSiteId || '';
      if ( siteName && ! seenSiteNames.includes( siteName ) ) {
        seenSiteNames.push( siteName );
      }
      if ( seenSiteNames.length ) {
        const processed = data.processedSites || 0;
        const total     = data.totalSites     || '?';
        siteEl.innerHTML =
          'Sites (' + processed + ' / ' + total + '):<br>' +
          seenSiteNames.map( function ( n ) { return '&nbsp;&bull;&nbsp;' + escHtml( n ); } ).join( '<br>' );
      } else {
        siteEl.innerHTML = '';
      }
    }

    if ( countsEl ) {
      const processed = data.processedProducts || 0;
      const total     = data.totalProducts     || '?';
      const failed    = data.failedCount       || 0;
      const skipped   = data.skippedCount      || 0;

      countsEl.innerHTML =
        '<span>Products: <strong>' + processed + ' / ' + total + '</strong></span>' +
        ( failed  ? '&nbsp;&nbsp;<span style="color:#c0392b">Failed: '  + failed  + '</span>' : '' ) +
        ( skipped ? '&nbsp;&nbsp;<span>Skipped: ' + skipped + '</span>' : '' );
    }
  }

  function handoffToNextRun( data ) {
    const startedBy        = data.startedBy        || 'system';
    const nextRunStartedBy = data.nextRunStartedBy || 'unknown';
    const staleReason      = data.stale_reason     || '';

    let msg;
    if ( data.status === 'failed' ) {
      msg = 'Save products started by ' + startedBy + ' failed. Starting save products requested by ' + nextRunStartedBy + '…';
    } else if ( data.status === 'completed' ) {
      msg = 'Save products started by ' + startedBy + ' completed. Starting save products requested by ' + nextRunStartedBy + '…';
    } else if ( data.status === 'stale' && staleReason === 'worker_never_started' ) {
      msg = 'Save products started by ' + startedBy + ' did not start. Starting save products requested by ' + nextRunStartedBy + '…';
    } else if ( data.status === 'stale' && staleReason === 'worker_started_no_heartbeat' ) {
      msg = 'Save products started by ' + startedBy + ' stopped responding. Starting save products requested by ' + nextRunStartedBy + '…';
    } else if ( data.status === 'stale' && staleReason === 'crashed_mid_run' ) {
      msg = 'Save products started by ' + startedBy + ' crashed. Starting save products requested by ' + nextRunStartedBy + '…';
    } else {
      msg = 'Previous deploy finished. Starting save products requested by ' + nextRunStartedBy + '…';
    }

    setStep( msg );
    runId = data.nextRunId;
    localStorage.setItem( LS_KEY, runId );
    schedulePoll( POLL_INTERVAL_MS );
  }

  function finaliseDeploy( data ) {
    clearTimeout( pollTimer );
    localStorage.removeItem( LS_KEY );

    const success   = data.status === 'completed';
    const cancelled = data.status === 'cancelled';
    const stale     = data.status === 'stale';
    const errors    = ( data.errors && data.errors.length ) ? data.errors : [];

    let staleMessage = 'Deploy did not complete. Please try again.';
    if ( data.stale_reason === 'crashed_mid_run' ) {
      staleMessage = 'Deploy failed — background process crashed. Please try again.';
    } else if ( data.stale_reason === 'worker_never_started' ) {
      staleMessage = 'Deploy did not start — the background process could not be launched. Please try again.';
    } else if ( data.stale_reason === 'worker_started_no_heartbeat' ) {
      staleMessage = 'Deploy started but stopped responding. The background process launched but did not complete. Please try again.';
    }

    const messageText = success   ? 'Deploy completed successfully.'
                      : cancelled ? 'Deploy cancelled.'
                      : stale     ? staleMessage
                      :             ( data.message || 'Deploy failed.' );

    // Build result content with DOM APIs so no untrusted text reaches innerHTML.
    const frag = document.createDocumentFragment();
    frag.appendChild( document.createTextNode( messageText ) );

    if ( errors.length ) {
      const ul = document.createElement( 'ul' );
      ul.style.marginTop = '6px';
      errors.forEach( function ( e ) {
        const li = document.createElement( 'li' );
        li.textContent = e;
        ul.appendChild( li );
      } );
      frag.appendChild( ul );
    }

    if ( success ) {
      const counts = document.createElement( 'span' );
      const saved  = parseInt( data.processedProducts, 10 ) || 0;
      const sites  = parseInt( data.processedSites,    10 ) || 0;
      // Only integer literals enter innerHTML here — no untrusted strings.
      counts.innerHTML =
        '<br>Products saved: <strong>' + saved + '</strong>' +
        '&nbsp;&nbsp;Sites: <strong>' + sites + '</strong>';
      frag.appendChild( counts );
    }

    showResult( success, frag );
    resetUi();

    // Convert Cancel → Done (acts like the × button)
    if ( cancelBtn ) {
      cancelBtn.dataset.mode  = 'done';
      cancelBtn.textContent   = 'Done';
      cancelBtn.className     = 'btn btn-sm btn-success';
      cancelBtn.disabled      = false;
      cancelBtn.style.display = 'inline-block';
    }
  }

  // ── UI helpers ─────────────────────────────────────────────────────────────

  function setPercent( pct ) {
    if ( ! progressBar ) return;
    progressBar.style.width = Math.min( pct, 100 ) + '%';
    progressBar.setAttribute( 'aria-valuenow', pct );
    progressBar.textContent = pct + '%';
  }

  function setStep( text ) {
    if ( stepEl ) stepEl.textContent = text;
  }

  // content may be a plain string (set via textContent) or a pre-built Node.
  // cssType overrides the success/error class when provided (e.g. 'warning').
  function showResult( success, content, cssType ) {
    if ( ! resultEl ) return;
    resultEl.style.display = 'block';
    resultEl.className     = cssType ? 'notice notice-' + cssType
                                     : ( success ? 'notice notice-success' : 'notice notice-error' );
    resultEl.innerHTML     = '';
    const p = document.createElement( 'p' );
    if ( content instanceof Node ) {
      p.appendChild( content );
    } else {
      p.textContent = String( content );
    }
    resultEl.appendChild( p );
  }

  function showQueueNotice( text ) {
    if ( ! queueNotice ) return;
    queueNotice.textContent    = text;
    queueNotice.style.display  = 'block';
    setTimeout( function () { queueNotice.style.display = 'none'; }, 8000 );
  }

  function resetUi() {
    setDeployBtnsDisabled( false );
    if ( cancelBtn ) {
      cancelBtn.style.display = 'none';
      cancelBtn.disabled      = false;
    }
    if ( modalClose ) modalClose.disabled = false;
    runId                  = null;
    lastStatus             = null;
    lastUpdatedAt          = null;
    lastUpdatedAtTimestamp = 0;
    setPercent( 100 );
  }

  function escHtml( str ) {
    return String( str )
      .replace( /&/g, '&amp;' )
      .replace( /</g, '&lt;'  )
      .replace( />/g, '&gt;'  )
      .replace( /"/g, '&quot;' );
  }

  // ── Fetch wrapper ──────────────────────────────────────────────────────────

  function apiFetch( url, options = {} ) {
    return fetch( url, Object.assign( {
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
    }, options ) );
  }

} )();
