(function () {
    'use strict';

    function markPage() {
        var path = window.location.pathname;

        document.body.classList.toggle('products-page', path.indexOf('/admin/products') !== -1);
        document.body.classList.toggle('categories-page', path.indexOf('/admin/categories') !== -1);
        document.body.classList.toggle('suppliers-page', path.indexOf('/admin/suppliers') !== -1);
        document.body.classList.toggle('customers-page', path.indexOf('/admin/customers') !== -1);
        document.body.classList.toggle('transactions-page', path.indexOf('/admin/transactions') !== -1);
        document.body.classList.toggle('stock-page', path.indexOf('/admin/stock') !== -1);
        document.body.classList.toggle('returns-page', path.indexOf('/admin/returns') !== -1);
        document.body.classList.toggle('finance-page', path.indexOf('/admin/finance') !== -1);
        document.body.classList.toggle('reports-page', path.indexOf('/admin/reports') !== -1);
        document.body.classList.toggle('users-page', path.indexOf('/admin/users') !== -1);
        document.body.classList.toggle('logs-page', path.indexOf('/admin/logs') !== -1);
        document.body.classList.toggle('promos-page', path.indexOf('/admin/promos') !== -1);
    }

    function markTables() {
        var path = window.location.pathname;

        document.querySelectorAll('table').forEach(function (table, index) {
            if (!table.id) {
                table.id = 'kasirDataTable' + index;
            }

            table.classList.add('kasir-datatable');

            if (path.indexOf('/admin/products') !== -1) table.classList.add('product-table', 'products-table');
            if (path.indexOf('/admin/categories') !== -1) table.classList.add('category-table', 'categories-table');
            if (path.indexOf('/admin/suppliers') !== -1) table.classList.add('supplier-table', 'suppliers-table');
            if (path.indexOf('/admin/customers') !== -1) table.classList.add('customer-table', 'customers-table');
            if (path.indexOf('/admin/transactions') !== -1) table.classList.add('transaction-table', 'transactions-table');
            if (path.indexOf('/admin/stock') !== -1) table.classList.add('stock-table');
            if (path.indexOf('/admin/returns') !== -1) table.classList.add('return-table', 'returns-table');
            if (path.indexOf('/admin/finance') !== -1) table.classList.add('finance-table');
            if (path.indexOf('/admin/reports') !== -1) table.classList.add('report-table');
            if (path.indexOf('/admin/users') !== -1) table.classList.add('user-table');
            if (path.indexOf('/admin/logs') !== -1) table.classList.add('log-table');
        });
    }

    function cleanInvalidRows(table) {
        table.querySelectorAll('tbody tr').forEach(function (row) {
            var colspanCell = row.querySelector('td[colspan], th[colspan]');
            var rowspanCell = row.querySelector('td[rowspan], th[rowspan]');

            if (colspanCell || rowspanCell) {
                row.remove();
            }
        });
    }

    function getHeaderCount(table) {
        var row = table.querySelector('thead tr:last-child');
        if (!row) return 0;
        return row.querySelectorAll('th,td').length;
    }

    function hasBadRow(table, headerCount) {
        var rows = table.querySelectorAll('tbody tr');

        for (var i = 0; i < rows.length; i++) {
            var cells = rows[i].querySelectorAll('td,th');

            if (cells.length > 0 && cells.length !== headerCount) {
                return true;
            }
        }

        return false;
    }

    function canInitTable(table) {
        cleanInvalidRows(table);

        var headerCount = getHeaderCount(table);

        if (headerCount <= 0) {
            console.warn('DataTables skip: thead kosong', table);
            return false;
        }

        if (hasBadRow(table, headerCount)) {
            console.warn('DataTables skip: jumlah kolom tbody tidak sama dengan thead', table);
            return false;
        }

        return true;
    }

    function getTableWidth(table) {
        if (table && table.dataset && table.dataset.tableWidth) {
            var customWidth = parseInt(table.dataset.tableWidth, 10);
            if (!isNaN(customWidth) && customWidth >= 480) return customWidth;
        }

        var body = document.body;

        if (body.classList.contains('products-page')) return 1380;
        if (body.classList.contains('categories-page')) return 980;
        if (body.classList.contains('suppliers-page')) return 1100;
        if (body.classList.contains('customers-page')) return 1220;
        if (body.classList.contains('transactions-page')) return 840;
        if (body.classList.contains('stock-page')) return 1180;
        if (body.classList.contains('returns-page')) return 1180;
        if (body.classList.contains('finance-page')) return 1120;
        if (body.classList.contains('promos-page')) return 1580;

        return 980;
    }

    function isMobileCardTable(table) {
        return !!(table && table.dataset && table.dataset.mobileCards === 'true' && window.matchMedia('(max-width: 640px)').matches);
    }

    function setTableDimension(table, width) {
        if (!table) return;
        if (width === 'mobile') {
            table.style.setProperty('width', '100%', 'important');
            table.style.setProperty('min-width', '0', 'important');
            table.style.setProperty('max-width', '100%', 'important');
            table.style.setProperty('table-layout', 'auto', 'important');
            return;
        }
        table.style.setProperty('width', width + 'px', 'important');
        table.style.setProperty('min-width', width + 'px', 'important');
        table.style.setProperty('max-width', 'none', 'important');
        table.style.setProperty('table-layout', 'fixed', 'important');
    }

    function applyTableWidth(table) {
        if (isMobileCardTable(table)) {
            setTableDimension(table, 'mobile');
            return;
        }
        setTableDimension(table, getTableWidth(table));
    }

    function initDataTables() {
        markPage();
        markTables();

        if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) {
            console.warn('DataTables belum ke-load. Cek CDN di admin-footer.php');
            return;
        }

        document.querySelectorAll('table.kasir-datatable:not(.no-datatable)').forEach(function (table) {
            if (jQuery.fn.DataTable.isDataTable(table)) {
                return;
            }

            if (!canInitTable(table)) {
                return;
            }

            applyTableWidth(table);

            jQuery(table).DataTable({
                responsive: false,
                scrollX: true,
                scrollCollapse: true,
                autoWidth: false,
                pageLength: 10,
                lengthMenu: [
                    [10, 25, 50, 100, -1],
                    [10, 25, 50, 100, 'Semua']
                ],
                ordering: true,
                // Jangan biarkan DataTables memaksa kolom pertama ASC saat pertama dibuka.
                // Urutan awal mengikuti ORDER BY dari backend, sehingga data terbaru (ID/tanggal DESC)
                // tetap muncul di paling atas. User masih bisa klik header untuk sorting manual.
                order: [],
                searching: true,
                columnDefs: [
                    { targets: 'no-sort', orderable: false, searchable: false }
                ],
                language: {
                    search: 'Cari:',
                    lengthMenu: 'Tampilkan _MENU_ data',
                    info: 'Menampilkan _START_ sampai _END_ dari _TOTAL_ data',
                    infoEmpty: 'Menampilkan 0 sampai 0 dari 0 data',
                    infoFiltered: '(difilter dari _MAX_ total data)',
                    zeroRecords: 'Data tidak ditemukan',
                    emptyTable: 'Belum ada data',
                    paginate: {
                        first: 'Awal',
                        previous: '‹',
                        next: '›',
                        last: 'Akhir'
                    }
                },
                initComplete: function () {
                    fixScroll();
                    syncHeaderBodyWidth();
                },
                drawCallback: function () {
                    fixScroll();
                    syncHeaderBodyWidth();
                }
            });
        });

        fixScroll();
        syncHeaderBodyWidth();
    }

    function fixScroll() {
        document.querySelectorAll('.dataTables_scrollBody').forEach(function (el) {
            el.style.overflowX = 'auto';
            el.style.overflowY = 'hidden';
            el.style.webkitOverflowScrolling = 'touch';
            el.style.maxWidth = '100%';
        });

        try {
            if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
                jQuery.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
            }
        } catch (e) {}
    }

    function syncHeaderBodyWidth() {
        document.querySelectorAll('.dataTables_wrapper').forEach(function (wrapper) {
            var bodyTable = wrapper.querySelector('.dataTables_scrollBody table') || wrapper.querySelector('table.kasir-datatable');
            if (!bodyTable) return;
            var mobileCards = isMobileCardTable(bodyTable);
            var width = mobileCards ? null : getTableWidth(bodyTable);

            wrapper.querySelectorAll('.dataTables_scrollHeadInner').forEach(function (inner) {
                inner.style.setProperty('width', mobileCards ? '100%' : width + 'px', 'important');
                inner.style.setProperty('min-width', mobileCards ? '0' : width + 'px', 'important');
            });

            wrapper.querySelectorAll('.dataTables_scrollHead table, .dataTables_scrollBody table').forEach(function (table) {
                // Clone header DataTables tidak selalu membawa dataset. Gunakan bodyTable sebagai sumber mode.
                setTableDimension(table, mobileCards ? 'mobile' : width);
            });
        });
    }

    function refreshTables() {
        fixScroll();
        syncHeaderBodyWidth();

        try {
            if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
                jQuery.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
            }
        } catch (e) {}
    }

    document.addEventListener('DOMContentLoaded', function () {
        markPage();

        setTimeout(initDataTables, 200);
        setTimeout(refreshTables, 600);
        setTimeout(refreshTables, 1200);

        document.querySelectorAll('img').forEach(function (img) {
            img.addEventListener('load', function () {
                setTimeout(refreshTables, 100);
            });
        });
    });

    window.addEventListener('load', function () {
        setTimeout(refreshTables, 300);
        setTimeout(refreshTables, 900);
    });

    window.addEventListener('resize', function () {
        setTimeout(refreshTables, 200);
    });
})();