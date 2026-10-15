(function () {
    'use strict';

    function hideLoader() {
        var loader = document.getElementById('pageLoader');

        if (loader) {
            loader.classList.add('hide');
            loader.classList.add('loader-hide');
            loader.classList.add('is-hidden');
            loader.style.display = 'none';
            loader.style.opacity = '0';
            loader.style.visibility = 'hidden';
            loader.style.pointerEvents = 'none';
        }

        if (document.body) {
            document.body.classList.add('loaded');
        }
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

    function initSidebar() {
        var sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
        var toggle = document.getElementById('sidebarToggle') || document.querySelector('.sidebar-toggle');

        if (!sidebar || !toggle) {
            return;
        }

        var backdrop = ensureSidebarBackdrop();

        function openSidebar() {
            sidebar.classList.add('show');
            sidebar.classList.add('active');
            sidebar.classList.add('open');
            document.body.classList.add('sidebar-open');
        }

        function closeSidebar() {
            sidebar.classList.remove('show');
            sidebar.classList.remove('active');
            sidebar.classList.remove('open');
            document.body.classList.remove('sidebar-open');
        }

        if (toggle.dataset.sidebarFinalBound !== '1') {
            toggle.dataset.sidebarFinalBound = '1';

            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (
                    sidebar.classList.contains('show') ||
                    sidebar.classList.contains('active') ||
                    sidebar.classList.contains('open')
                ) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        if (backdrop.dataset.sidebarFinalBound !== '1') {
            backdrop.dataset.sidebarFinalBound = '1';

            backdrop.addEventListener('click', function () {
                closeSidebar();
            });
        }

        document.querySelectorAll('.admin-sidebar a').forEach(function (link) {
            if (link.dataset.closeSidebarFinalBound === '1') {
                return;
            }

            link.dataset.closeSidebarFinalBound = '1';

            link.addEventListener('click', function () {
                if (window.innerWidth <= 991) {
                    closeSidebar();
                }
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });
    }

    function moveHeaderActions() {
        document.querySelectorAll('.admin-main .admin-topbar, .admin-main .page-topbar').forEach(function (header) {
            var actions = header.querySelector(':scope > .topbar-actions');

            if (!actions || actions.classList.contains('header-actions-moved')) {
                return;
            }

            var hasContent = actions.children.length > 0 || actions.textContent.trim() !== '';

            if (!hasContent) {
                actions.classList.add('header-actions-moved');
                return;
            }

            var bar = document.createElement('div');
            bar.className = 'page-action-bar';

            while (actions.firstChild) {
                bar.appendChild(actions.firstChild);
            }

            actions.classList.add('header-actions-moved');
            header.insertAdjacentElement('afterend', bar);
        });
    }

    function initDisabledMenu() {
        document.querySelectorAll('.disabled-menu, .shortcut-btn.disabled').forEach(function (item) {
            if (item.dataset.disabledFinalBound === '1') {
                return;
            }

            item.dataset.disabledFinalBound = '1';

            item.addEventListener('click', function (e) {
                e.preventDefault();

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: 'Modul ini akan dibuat di tahap berikutnya.',
                        showConfirmButton: false,
                        timer: 2500,
                        timerProgressBar: true
                    });
                } else {
                    alert('Modul ini akan dibuat di tahap berikutnya.');
                }
            });
        });
    }
function applyPageSpecificClass() {
    var path = window.location.pathname;

    if (path.indexOf('/admin/stock') !== -1) {
        document.body.classList.add('stock-page');

        setTimeout(function () {
            document.querySelectorAll('.page-action-bar').forEach(function (bar) {
                bar.classList.add('stock-action-bar');
            });
        }, 100);
    }

    if (path.indexOf('/admin/transactions') !== -1) {
        document.body.classList.add('transactions-page');
    }

    if (path.indexOf('/admin/products') !== -1) {
        document.body.classList.add('products-page');
    }

    if (path.indexOf('/admin/categories') !== -1) {
        document.body.classList.add('categories-page');
    }

    if (path.indexOf('/admin/suppliers') !== -1) {
        document.body.classList.add('suppliers-page');
    }

    if (path.indexOf('/admin/customers') !== -1) {
        document.body.classList.add('customers-page');
    }
}
    function initAll() {
    applyPageSpecificClass();

    try {
        initSidebar();
    } catch (e) {
        console.warn('Sidebar error:', e);
    }

    try {
        moveHeaderActions();
    } catch (e) {
        console.warn('Header action error:', e);
    }

    try {
        initDisabledMenu();
    } catch (e) {
        console.warn('Disabled menu error:', e);
    }

    setTimeout(hideLoader, 150);
}

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    window.addEventListener('load', function () {
        setTimeout(hideLoader, 100);
    });

    setTimeout(hideLoader, 1200);
    setTimeout(hideLoader, 2500);
})();