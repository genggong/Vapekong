(function () {
    function mergeClass(current) {
        if (typeof current === 'string') {
            return {
                popup: (current + ' kasirpro-swal-popup').trim(),
                confirmButton: 'kasirpro-swal-confirm',
                cancelButton: 'kasirpro-swal-cancel'
            };
        }

        return Object.assign({
            popup: 'kasirpro-swal-popup',
            confirmButton: 'kasirpro-swal-confirm',
            cancelButton: 'kasirpro-swal-cancel'
        }, current || {});
    }

    function applyKasirproSwalTheme() {
        if (!window.Swal || window.__kasirproSwalThemeApplied) return;
        window.__kasirproSwalThemeApplied = true;

        const originalFire = window.Swal.fire.bind(window.Swal);

        window.Swal.fire = function () {
            let opts;

            if (arguments.length === 1 && arguments[0] && typeof arguments[0] === 'object') {
                opts = Object.assign({}, arguments[0]);
            } else {
                opts = {
                    title: arguments[0],
                    text: arguments[1] || '',
                    icon: arguments[2] || undefined
                };
            }

            const isToast = opts.toast === true || opts.position === 'top-end' || opts.position === 'top-start' || opts.position === 'bottom-end' || opts.position === 'bottom-start';

            if (isToast) {
                opts.toast = true;
                opts.backdrop = false;
                opts.showConfirmButton = opts.showConfirmButton ?? false;
                opts.timer = opts.timer ?? 2200;
                opts.timerProgressBar = opts.timerProgressBar ?? true;
                opts.customClass = Object.assign({
                    popup: 'kasirpro-swal-toast'
                }, opts.customClass || {});
            } else {
                opts.customClass = mergeClass(opts.customClass);
                opts.buttonsStyling = opts.buttonsStyling ?? true;
                opts.confirmButtonText = opts.confirmButtonText || 'OK';
            }

            return originalFire(opts);
        };
    }

    applyKasirproSwalTheme();
    document.addEventListener('DOMContentLoaded', applyKasirproSwalTheme);
})();
