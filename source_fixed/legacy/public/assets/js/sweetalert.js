(function () {
    function hasSwal() {
        return typeof window.Swal !== 'undefined';
    }

    window.toastSuccess = function (message = 'Berhasil') {
        if (!hasSwal()) return alert(message);
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: message,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: '#ffffff',
            color: '#0f172a',
            customClass: { popup: 'premium-toast' },
            didOpen: (toast) => {
                toast.addEventListener('mouseenter', Swal.stopTimer);
                toast.addEventListener('mouseleave', Swal.resumeTimer);
            }
        });
    };

    window.toastError = function (message = 'Terjadi kesalahan') {
        if (!hasSwal()) return alert(message);
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'error',
            title: message,
            showConfirmButton: false,
            timer: 3500,
            timerProgressBar: true,
            background: '#ffffff',
            color: '#0f172a',
            customClass: { popup: 'premium-toast' }
        });
    };

    window.toastWarning = function (message = 'Perhatian') {
        if (!hasSwal()) return alert(message);
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'warning',
            title: message,
            showConfirmButton: false,
            timer: 3500,
            timerProgressBar: true,
            background: '#ffffff',
            color: '#0f172a',
            customClass: { popup: 'premium-toast' }
        });
    };

    window.toastInfo = function (message = 'Informasi') {
        if (!hasSwal()) return alert(message);
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: message,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: '#ffffff',
            color: '#0f172a',
            customClass: { popup: 'premium-toast' }
        });
    };

    window.confirmDelete = function (message = 'Data yang dihapus tidak bisa dikembalikan.') {
        if (!hasSwal()) {
            return Promise.resolve({ isConfirmed: confirm(message) });
        }

        return Swal.fire({
            title: 'Hapus data?',
            text: message,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Ya, hapus',
            cancelButtonText: 'Batal',
            reverseButtons: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            customClass: { popup: 'premium-alert' }
        });
    };

    window.confirmAction = function (options = {}) {
        if (!hasSwal()) {
            return Promise.resolve({ isConfirmed: confirm(options.text || 'Lanjutkan aksi ini?') });
        }

        return Swal.fire({
            title: options.title || 'Konfirmasi',
            text: options.text || 'Lanjutkan aksi ini?',
            icon: options.icon || 'question',
            showCancelButton: true,
            confirmButtonText: options.confirmText || 'Ya, lanjut',
            cancelButtonText: options.cancelText || 'Batal',
            reverseButtons: true,
            confirmButtonColor: options.confirmColor || '#dc2626',
            cancelButtonColor: '#64748b',
            customClass: { popup: 'premium-alert' }
        });
    };

    window.loadingAlert = function (message = 'Memproses data...') {
        if (!hasSwal()) return;
        Swal.fire({
            title: message,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            customClass: { popup: 'premium-alert' },
            didOpen: () => Swal.showLoading()
        });
    };
})();
