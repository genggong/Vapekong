function attachDeleteConfirm() {
    document.querySelectorAll('.btn-delete:not([data-delete-bound="1"])').forEach(btn => {
        btn.setAttribute('data-delete-bound', '1');
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const url = this.getAttribute('href');
            confirmDelete('Data akan dihapus dari sistem.').then(result => {
                if (result.isConfirmed) window.location.href = url;
            });
        });
    });
}

function attachPremiumFormLoading() {
    document.querySelectorAll('form:not([data-loading-bound="1"])').forEach(form => {
        form.setAttribute('data-loading-bound', '1');

        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"], .btn-premium');
            if (btn && btn.tagName.toLowerCase() === 'button') {
                btn.disabled = true;
                btn.dataset.originalText = btn.innerHTML;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const pageLoader = document.getElementById('pageLoader');
    if (pageLoader) setTimeout(() => pageLoader.classList.add('hide'), 350);

    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('adminSidebar');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function () {
            sidebar.classList.toggle('show');
        });
    }

    document.addEventListener('click', function (event) {
        if (window.innerWidth <= 991 && sidebar && sidebarToggle) {
            if (
                sidebar.classList.contains('show') &&
                !sidebar.contains(event.target) &&
                !sidebarToggle.contains(event.target)
            ) {
                sidebar.classList.remove('show');
            }
        }
    });

    document.querySelectorAll('.disabled-menu, .shortcut-btn.disabled').forEach(item => {
        item.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof toastInfo === 'function') {
                toastInfo('Modul ini akan dibuat di tahap berikutnya.');
            }
        });
    });

    attachDeleteConfirm();
    attachPremiumFormLoading();
});
