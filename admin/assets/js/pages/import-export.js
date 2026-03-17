(function() {
    'use strict';

    var importData = null;
    var exportSvg = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> ';

    /**
     * Build the import preview summary using safe DOM methods.
     *
     * @param {Object} data Parsed export JSON.
     * @param {HTMLElement} container Target element to populate.
     */
    function renderPreviewSummary(data, container) {
        // Clear previous content.
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }

        var lines = [];
        lines.push({ label: 'Version: ', value: String(data.version || 'unknown') });
        lines.push({ label: 'Exported: ', value: String(data.exported_at || 'unknown') });

        lines.forEach(function(item) {
            var b = document.createElement('strong');
            b.textContent = item.label;
            container.appendChild(b);
            container.appendChild(document.createTextNode(item.value));
            container.appendChild(document.createElement('br'));
        });

        var checks = [];
        if (data.settings) checks.push('Plugin settings');
        if (data.gcm_settings) checks.push('Google Consent Mode settings');
        if (data.banners && data.banners.length) checks.push(data.banners.length + ' banner configuration(s)');
        if (data.categories && data.categories.length) checks.push(data.categories.length + ' cookie category/ies');
        if (data.cookies && data.cookies.length) checks.push(data.cookies.length + ' cookie(s)');

        checks.forEach(function(text) {
            container.appendChild(document.createTextNode('\u2713 ' + text));
            container.appendChild(document.createElement('br'));
        });
    }

    // --- Export ---
    document.getElementById('faz-export-btn').addEventListener('click', function() {
        this.disabled = true;
        this.textContent = 'Exporting...';
        var btn = this;

        FAZ.get('settings/export').then(function(data) {
            var blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'faz-settings-' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
            FAZ.notify('Settings exported successfully.', 'success');
            btn.disabled = false;
            btn.textContent = '';
            var temp = document.createElement('span');
            temp.innerHTML = exportSvg; // Safe: static SVG string defined in this file.
            while (temp.firstChild) btn.appendChild(temp.firstChild);
            btn.appendChild(document.createTextNode('Export Settings'));
        }).catch(function(err) {
            FAZ.notify('Export failed: ' + (err.message || err), 'error');
            btn.disabled = false;
            btn.textContent = '';
            var temp = document.createElement('span');
            temp.innerHTML = exportSvg;
            while (temp.firstChild) btn.appendChild(temp.firstChild);
            btn.appendChild(document.createTextNode('Export Settings'));
        });
    });

    // --- Import: File Select ---
    document.getElementById('faz-import-file').addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;

        var reader = new FileReader();
        reader.onload = function(ev) {
            try {
                importData = JSON.parse(ev.target.result);
            } catch (err) {
                FAZ.notify('Invalid JSON file.', 'error');
                importData = null;
                return;
            }

            // Validate structure
            if (!importData.plugin || importData.plugin !== 'faz-cookie-manager') {
                FAZ.notify('This file is not a FAZ Cookie Manager export.', 'error');
                importData = null;
                return;
            }

            // Show preview using safe DOM methods.
            var preview = document.getElementById('faz-import-preview');
            var summary = document.getElementById('faz-import-summary');
            renderPreviewSummary(importData, summary);
            preview.style.display = 'block';
            document.getElementById('faz-import-btn').disabled = false;
        };
        reader.readAsText(file);
    });

    // --- Import: Apply ---
    document.getElementById('faz-import-btn').addEventListener('click', function() {
        if (!importData) return;
        if (!confirm('This will overwrite your current settings. Continue?')) return;

        this.disabled = true;
        var statusEl = document.getElementById('faz-import-status');
        statusEl.style.display = 'block';
        statusEl.textContent = 'Importing...';
        statusEl.style.color = 'var(--faz-text-secondary)';

        FAZ.post('settings/import', importData).then(function(result) {
            statusEl.textContent = 'Import completed successfully. Reloading...';
            statusEl.style.color = 'var(--faz-success)';
            FAZ.notify('Settings imported successfully.', 'success');
            setTimeout(function() { window.location.reload(); }, 1500);
        }).catch(function(err) {
            statusEl.textContent = 'Import failed: ' + (err.message || err);
            statusEl.style.color = 'var(--faz-danger)';
            document.getElementById('faz-import-btn').disabled = false;
        });
    });

})();
