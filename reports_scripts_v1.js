// reports_scripts.js - Reports page functionality

(() => {
    const form = document.getElementById('filtersForm');
    if (!form) return;

    const calcBtn = form.querySelector('button[name="run"][value="1"]');
    const resetBtn = form.querySelector('button[type="button"]');
    const selects = form.querySelectorAll('select');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    const progressContainer = document.querySelector('.progress-container');

    let busy = false;

    function setBusyUI(on) {
        form.classList.toggle('is-busy', on);
        if (on) form.setAttribute('aria-busy', 'true');
        else form.removeAttribute('aria-busy');
    }

    async function calculateReportAjax(e) {
        e.preventDefault();

        if (busy) return;
        busy = true;
        setBusyUI(true);
        calcBtn.disabled = true;
        resetBtn.disabled = true;

        try {
            // Get form values
            const formData = new FormData(form);
            const params = new URLSearchParams();
            for (let [key, value] of formData.entries()) {
                params.append(key, value);
            }

            // Step 1: Get count (5%)
            progressText.innerHTML = '<span>Lasketaan rivejä...</span>';
            progressFill.style.width = '5%';

            const countResp = await fetch('reports_count.php?' + params.toString());
            const countData = await countResp.json();

            if (!countData.success) {
                throw new Error(countData.error || 'Count failed');
            }

            const { total, chunkSize } = countData;

            if (total === 0) {
                alert('Ei spotteja valitulla aikavälillä');
                return;
            }

            // Step 2: Process in chunks (5% -> 85%)
            let processedCount = 0;
            let totalReach = 0;
            let totalAvgViewers = 0;

            for (let offset = 0; offset < total; offset += chunkSize) {
                params.set('offset', offset);
                params.set('limit', chunkSize);

                const chunkResp = await fetch('reports_process.php?' + params.toString());
                const chunkData = await chunkResp.json();

                if (!chunkData.success) {
                    throw new Error(chunkData.error || 'Processing failed');
                }

                processedCount += chunkData.processed;
                totalReach += chunkData.reachSum;
                totalAvgViewers += chunkData.avgViewersSum;

                // Update progress (5% -> 85%)
                const percentComplete = Math.min(85, Math.floor((processedCount / total) * 80) + 5);
                progressFill.style.width = percentComplete + '%';
                progressText.innerHTML = `<span>Käsitelty ${processedCount.toLocaleString('fi-FI')} / ${total.toLocaleString('fi-FI')} spottia...</span>`;
            }

            // Step 3: Save to session (85% -> 90%)
            progressFill.style.width = '88%';
            progressText.innerHTML = '<span>Tallennetaan tulokset...</span>';

            params.set('run', '1');
            params.set('save_results', '1');
            params.set('total_reach', totalReach);
            params.set('total_avg_viewers', totalAvgViewers);
            params.set('processed_count', processedCount);

            const saveResp = await fetch('reports_save.php?' + params.toString());
            const saveData = await saveResp.json();

            if (!saveData.success) {
                throw new Error('Tulosten tallennus epäonnistui');
            }

            // Step 4: Finalize (90% -> 95%)
            progressFill.style.width = '93%';
            progressText.innerHTML = '<span>Luodaan yhteenveto...</span>';
            await new Promise(r => setTimeout(r, 200));

            // Step 5: Redirect (95% -> 100%)
            progressFill.style.width = '97%';
            progressText.innerHTML = '<span>Siirrytään raporttiin...</span>';
            await new Promise(r => setTimeout(r, 200));

            progressFill.style.width = '100%';

            // Clean params for redirect (remove processing-specific params)
            params.delete('offset');
            params.delete('limit');
            params.delete('save_results');
            params.delete('total_reach');
            params.delete('total_avg_viewers');
            params.delete('processed_count');
            params.set('from_ajax', '1');  // Flag to use session data instead of recalculating

            window.location.href = 'reports.php?' + params.toString();

        } catch (error) {
            alert('Virhe: ' + error.message);
            busy = false;
            setBusyUI(false);
            calcBtn.disabled = false;
            resetBtn.disabled = false;
            progressFill.style.width = '0%';
            progressText.textContent = 'Valmistellaan...';
        }
    }

    // Laske TRP with AJAX
    if (calcBtn) {
        calcBtn.addEventListener('click', calculateReportAjax);
    }

    // Select change handlers (keep existing behavior)
    selects.forEach(sel => {
        sel.addEventListener('change', () => {
            if (busy) return;
            busy = true;
            setBusyUI(true);
            const h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'run';
            h.value = '0';
            form.appendChild(h);
            requestAnimationFrame(() => form.submit());
        });
    });

    // bfcache handler
    window.addEventListener('pageshow', () => {
        busy = false;
        setBusyUI(false);
        if (calcBtn) calcBtn.disabled = false;
        if (resetBtn) resetBtn.disabled = false;
        progressFill.style.width = '0%';
        progressText.textContent = 'Valmistellaan...';
    });

    // Debug modal handlers
    (() => {
        const openBtn = document.getElementById('btnOpenDebug');
        const closeBtn = document.getElementById('btnCloseDebug');
        const modal = document.getElementById('debugModal');
        if (!modal || !openBtn || !closeBtn) return;

        function open() { 
            modal.classList.add('show'); 
            modal.setAttribute('aria-hidden', 'false'); 
        }
        
        function close() { 
            modal.classList.remove('show'); 
            modal.setAttribute('aria-hidden', 'true'); 
        }

        openBtn.addEventListener('click', open);
        closeBtn.addEventListener('click', close);
        modal.querySelector('.modal-backdrop')?.addEventListener('click', close);
        window.addEventListener('keydown', (e) => { 
            if (e.key === 'Escape') close(); 
        });
    })();
})();
