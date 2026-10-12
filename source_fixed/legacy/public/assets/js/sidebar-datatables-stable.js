(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function hideLoader() {
        var loader = document.getElementById('pageLoader');
        if (loader) {
            loader.classList.add('hide', 'loader-hide', 'is-hidden');
            loader.style.display = 'none';
            loader.style.opacity = '0';
            loader.style.visibility = 'hidden';
            loader.style.pointerEvents = 'none';
        }
        document.body.classList.add('loaded');
    }

    function ensureSidebarBackdrop() {
        var backdrop = document.getElementById('sidebarBackdrop');
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'sidebarBackdrop';
            backdrop.className = 'sidebar-backdrop';
            document.body.appendChild(backdrop);
        }
        return backdrop;
    }

    function openSidebar(sidebar) {
        sidebar.classList.add('show', 'active', 'open');
        document.body.classList.add('sidebar-open');
    }

    function closeSidebar(sidebar) {
        sidebar.classList.remove('show', 'active', 'open');
        document.body.classList.remove('sidebar-open');
    }

    function initSidebarStable() {
        var sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
        var toggle = document.getElementById('sidebarToggle') || document.querySelector('.sidebar-toggle');

        if (!sidebar || !toggle) return;

        var backdrop = ensureSidebarBackdrop();

        if (toggle.dataset.sidebarStableBound !== '1') {
            toggle.dataset.sidebarStableBound = '1';

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (
                    sidebar.classList.contains('show') ||
                    sidebar.classList.contains('active') ||
                    sidebar.classList.contains('open')
                ) {
                    closeSidebar(sidebar);
                } else {
                    openSidebar(sidebar);
                }
            }, true);
        }

        if (backdrop.dataset.sidebarStableBound !== '1') {
            backdrop.dataset.sidebarStableBound = '1';
            backdrop.addEventListener('click', function () {
                closeSidebar(sidebar);
            });
        }

        document.querySelectorAll('.admin-sidebar a').forEach(function (link) {
            if (link.dataset.sidebarCloseBound === '1') return;
            link.dataset.sidebarCloseBound = '1';
            link.addEventListener('click', function () {
                if (window.innerWidth <= 991) closeSidebar(sidebar);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSidebar(sidebar);
        });
    }

    function wrapTable(table) {
        if (!table || table.dataset.kpScrollWrapped === '1') return;

        var parent = table.parentElement;
        if (!parent) return;

        var parentClass = parent.className || '';
        var alreadyWrapped =
            parent.classList.contains('kp-table-scroll') ||
            parent.classList.contains('table-responsive') ||
            parent.classList.contains('dt-table-scroll') ||
            parentClass.indexOf('dataTables_scrollBody') !== -1 ||
            parentClass.indexOf('dt-scroll-body') !== -1;

        if (!alreadyWrapped) {
            var wrap = document.createElement('div');
            wrap.className = 'kp-table-scroll';
            parent.insertBefore(wrap, table);
            wrap.appendChild(table);
        } else {
            parent.classList.add('kp-table-scroll');
        }

        table.dataset.kpScrollWrapped = '1';
    }

    function markTablesByPage() {
        var path = window.location.pathname;

        document.querySelectorAll('table').forEach(function (table) {
            if (path.indexOf('/admin/products') !== -1) table.classList.add('product-table');
            if (path.indexOf('/admin/categories') !== -1) table.classList.add('category-table');
            if (path.indexOf('/admin/transactions') !== -1) table.classList.add('transaction-table');
            if (path.indexOf('/admin/stock') !== -1) table.classList.add('stock-table');

            var shouldWrap =
                table.classList.contains('dataTable') ||
                table.classList.contains('premium-table') ||
                table.classList.contains('admin-table') ||
                table.closest('.dataTables_wrapper') ||
                table.closest('.dt-container') ||
                path.indexOf('/admin/products') !== -1 ||
                path.indexOf('/admin/categories') !== -1 ||
                path.indexOf('/admin/transactions') !== -1 ||
                path.indexOf('/admin/stock') !== -1;

            if (shouldWrap) wrapTable(table);
        });
    }

    function fixDataTablesAfterInit() {
        markTablesByPage();

        if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
            try {
                jQuery.fn.dataTable.tables({ visible: true, api: true }).columns.adjust();
            } catch (e) {}
        }

        document.querySelectorAll('.dataTables_scrollBody, .dt-scroll-body').forEach(function (el) {
            el.style.overflowX = 'auto';
            el.style.webkitOverflowScrolling = 'touch';
        });
    }

    function observeTableChanges() {
        var target = document.querySelector('.admin-main') || document.body;
        var observer = new MutationObserver(function () {
            fixDataTablesAfterInit();
        });

        observer.observe(target, {
            childList: true,
            subtree: true
        });
    }

    ready(function () {
        initSidebarStable();
        fixDataTablesAfterInit();
        observeTableChanges();

        setTimeout(fixDataTablesAfterInit, 300);
        setTimeout(fixDataTablesAfterInit, 900);
        setTimeout(hideLoader, 200);
    });

    window.addEventListener('load', function () {
        initSidebarStable();
        fixDataTablesAfterInit();
        setTimeout(hideLoader, 100);
    });

    window.addEventListener('resize', function () {
        fixDataTablesAfterInit();
    });

    setTimeout(hideLoader, 1200);
})();
