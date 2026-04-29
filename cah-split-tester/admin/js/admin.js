(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('cah-test-form');
        if (!form) {
            return;
        }

        var table = document.getElementById('cah-variants-table');
        var tbody = table ? table.querySelector('tbody') : null;
        var addBtn = document.getElementById('cah-add-variant');
        var sumEl = document.getElementById('cah-weight-sum');
        var errEl = document.getElementById('cah-weight-error');

        function recalcSum() {
            if (!tbody || !sumEl) { return 0; }
            var total = 0;
            tbody.querySelectorAll('input.cah-weight').forEach(function (input) {
                var v = parseInt(input.value, 10);
                if (!isNaN(v)) { total += v; }
            });
            sumEl.textContent = String(total);
            sumEl.style.color = total === 100 ? '#008a20' : '#d63638';
            return total;
        }

        function reindexRows() {
            if (!tbody) { return; }
            var rows = tbody.querySelectorAll('tr.cah-variant-row');
            rows.forEach(function (row, i) {
                row.querySelectorAll('input, select').forEach(function (field) {
                    var name = field.getAttribute('name') || '';
                    var updated = name.replace(/variants\[\d+\]/, 'variants[' + i + ']');
                    field.setAttribute('name', updated);
                });
            });
        }

        function availableFiles() {
            if (!table) { return []; }
            var raw = table.getAttribute('data-available-files') || '[]';
            try { return JSON.parse(raw); } catch (e) { return []; }
        }

        function htmlFileSelect(index) {
            var files = availableFiles();
            var opts = '<option value="">— External URL —</option>';
            for (var i = 0; i < files.length; i++) {
                var f = files[i];
                opts += '<option value="' + f.replace(/"/g, '&quot;') + '">' + f + '</option>';
            }
            return '<select name="variants[' + index + '][html_file]" class="cah-html-file">' + opts + '</select>';
        }

        function addRow() {
            if (!tbody) { return; }
            var index = tbody.querySelectorAll('tr.cah-variant-row').length;
            var tr = document.createElement('tr');
            tr.className = 'cah-variant-row';
            tr.innerHTML =
                '<td><input type="text" name="variants[' + index + '][name]" class="regular-text" /></td>' +
                '<td><input type="text" name="variants[' + index + '][slug]" class="code" /></td>' +
                '<td>' + htmlFileSelect(index) + '</td>' +
                '<td><input type="url" name="variants[' + index + '][url]" /></td>' +
                '<td><input type="text" name="variants[' + index + '][pretty_path]" placeholder="my-page-b" class="code" /></td>' +
                '<td><input type="number" min="0" max="100" step="1" name="variants[' + index + '][weight]" value="0" class="small-text cah-weight" /></td>' +
                '<td><button type="button" class="button-link-delete cah-remove-variant">Remove</button></td>';
            tbody.appendChild(tr);
            recalcSum();
        }

        if (tbody) {
            tbody.addEventListener('input', function (e) {
                if (e.target && e.target.classList && e.target.classList.contains('cah-weight')) {
                    recalcSum();
                }
            });
            tbody.addEventListener('click', function (e) {
                if (e.target && e.target.classList && e.target.classList.contains('cah-remove-variant')) {
                    e.preventDefault();
                    var row = e.target.closest('tr');
                    if (row && tbody.querySelectorAll('tr.cah-variant-row').length > 1) {
                        row.parentNode.removeChild(row);
                        reindexRows();
                        recalcSum();
                    }
                }
            });
        }

        if (addBtn) {
            addBtn.addEventListener('click', function (e) {
                e.preventDefault();
                addRow();
            });
        }

        form.addEventListener('submit', function (e) {
            var total = recalcSum();
            if (total !== 100) {
                e.preventDefault();
                if (errEl) { errEl.style.display = 'block'; }
                sumEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else if (errEl) {
                errEl.style.display = 'none';
            }
        });

        recalcSum();
    });
})();
