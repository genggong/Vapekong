</div>

<?php
$currentPath = $_SERVER['REQUEST_URI'] ?? '';

if (!function_exists('bottom_active')) {
    function bottom_active(string $needle, string $currentPath): string
    {
        return strpos($currentPath, $needle) !== false ? 'active' : '';
    }
}
?>

<footer class="admin-footer">
    <div>
        <b><?= e(setting('app_name', 'KasirPro UMKM')); ?></b>
        <span>Panel kasir, stok, transaksi, dan keuangan UMKM.</span>
    </div>
    <small>Red Premium POS</small>
</footer>

<nav class="mobile-bottom-nav">
    <a href="<?= legacy_base_url('admin/dashboard.php'); ?>" class="<?= bottom_active('/admin/dashboard.php', $currentPath); ?>">
        <i class="bi bi-house-door-fill"></i>
        <span>Home</span>
    </a>

    <a href="<?= legacy_base_url('admin/pos/index.php'); ?>" class="<?= bottom_active('/admin/pos', $currentPath); ?>">
        <i class="bi bi-cart-check-fill"></i>
        <span>POS</span>
    </a>

    <a href="<?= legacy_base_url('admin/products/index.php'); ?>" class="<?= bottom_active('/admin/products', $currentPath); ?>">
        <i class="bi bi-box-seam-fill"></i>
        <span>Produk</span>
    </a>

    <a href="<?= legacy_base_url('admin/stock/index.php'); ?>" class="<?= bottom_active('/admin/stock', $currentPath); ?>">
        <i class="bi bi-inboxes-fill"></i>
        <span>Stok</span>
    </a>

    <a href="<?= legacy_base_url('admin/transactions/index.php'); ?>" class="<?= bottom_active('/admin/transactions', $currentPath); ?>">
        <i class="bi bi-receipt-cutoff"></i>
        <span>Trx</span>
    </a>
</nav>

<!-- jQuery wajib sebelum Bootstrap DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Bootstrap -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Bootstrap DataTables 1.13.8 -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

<!-- App JS -->
<script src="<?= legacy_base_url('assets/js/sweetalert.js?v=' . time()); ?>"></script>
<script src="<?= legacy_base_url('assets/js/app.js?v=' . time()); ?>"></script>
<script src="<?= legacy_base_url('assets/js/ui-final-reset.js?v=' . time()); ?>"></script>
<script src="<?= legacy_base_url('assets/js/pos-active-transaction-hardfix.js?v=' . time()); ?>"></script>

<!-- DataTables Custom WAJIB paling bawah -->
<script src="<?= legacy_base_url('assets/js/datatables-custom.js?v=' . time()); ?>"></script>

<?php
$flashSuccess = flash('success');
$flashError = flash('error');
$flashWarning = flash('warning');
$flashInfo = flash('info');
?>

<script>
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

    function initSidebar() {
        var sidebar = document.getElementById('adminSidebar') || document.querySelector('.admin-sidebar');
        var toggle = document.getElementById('sidebarToggle') || document.querySelector('.sidebar-toggle');

        if (!sidebar || !toggle) {
            return;
        }

        var backdrop = document.getElementById('sidebarBackdrop');

        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.id = 'sidebarBackdrop';
            backdrop.className = 'sidebar-backdrop';
            document.body.appendChild(backdrop);
        }

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

        if (toggle.dataset.sidebarBound !== '1') {
            toggle.dataset.sidebarBound = '1';

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

        if (backdrop.dataset.sidebarBound !== '1') {
            backdrop.dataset.sidebarBound = '1';

            backdrop.addEventListener('click', function () {
                closeSidebar();
            });
        }

        document.querySelectorAll('.admin-sidebar a').forEach(function (link) {
            if (link.dataset.closeSidebarBound === '1') {
                return;
            }

            link.dataset.closeSidebarBound = '1';

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

    function initDeleteConfirm() {
        document.querySelectorAll('.btn-delete').forEach(function (btn) {
            if (btn.dataset.deleteBound === '1') {
                return;
            }

            btn.dataset.deleteBound = '1';

            btn.addEventListener('click', function (e) {
                e.preventDefault();

                var href = this.getAttribute('href');

                if (typeof Swal === 'undefined') {
                    if (confirm('Yakin hapus data?')) {
                        window.location.href = href;
                    }
                    return;
                }

                Swal.fire({
                    title: 'Yakin hapus data?',
                    text: 'Data yang dihapus tidak bisa dikembalikan.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Hapus',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        window.location.href = href;
                    }
                });
            });
        });
    }

    function initFlashToast() {
        if (typeof Swal === 'undefined') {
            return;
        }

        var toast = Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2600,
            timerProgressBar: true
        });

        <?php if ($flashSuccess): ?>
        toast.fire({
            icon: 'success',
            title: <?= json_encode($flashSuccess); ?>
        });
        <?php endif; ?>

        <?php if ($flashError): ?>
        toast.fire({
            icon: 'error',
            title: <?= json_encode($flashError); ?>
        });
        <?php endif; ?>

        <?php if ($flashWarning): ?>
        toast.fire({
            icon: 'warning',
            title: <?= json_encode($flashWarning); ?>
        });
        <?php endif; ?>

        <?php if ($flashInfo): ?>
        toast.fire({
            icon: 'info',
            title: <?= json_encode($flashInfo); ?>
        });
        <?php endif; ?>
    }

    function initFormLoading() {
        document.querySelectorAll('form').forEach(function (form) {
            if (form.dataset.formLoadingBound === '1') {
                return;
            }

            form.dataset.formLoadingBound = '1';

            form.addEventListener('submit', function () {
                var submitBtn = form.querySelector('button[type="submit"]');

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.dataset.originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';
                }
            });
        });
    }

    function initAll() {
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
            initDeleteConfirm();
        } catch (e) {
            console.warn('Delete confirm error:', e);
        }

        try {
            initFlashToast();
        } catch (e) {
            console.warn('Flash toast error:', e);
        }

        try {
            initFormLoading();
        } catch (e) {
            console.warn('Form loading error:', e);
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
</script>

    <script src="<?= legacy_base_url('assets/js/sweetalert-theme.js?v=' . time()); ?>"></script>
    <script src="<?= legacy_base_url('assets/js/status-color-fixed.js?v=' . time()); ?>"></script>
    <script src="<?= legacy_base_url('assets/js/status-color-force.js?v=' . time()); ?>"></script>
    <script src="<?= legacy_base_url('assets/js/admin-ui-rescue.js?v=' . time()); ?>"></script>
    <script src="<?= legacy_base_url('assets/js/theme-color-settings.js?v=' . time()); ?>"></script>
</body>
</html>