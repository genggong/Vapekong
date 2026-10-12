(function(){
    const successWords = [
        'success','sukses','berhasil','paid','lunas','completed','complete','selesai',
        'active','aktif','approved','open','settled','settlement'
    ];
    const pendingWords = [
        'pending','menunggu','proses','processing','process','draft','partial','sebagian',
        'unpaid','belum','waiting','tertunda'
    ];
    const failedWords = [
        'failed','fail','gagal','error','cancel','cancelled','canceled','batal',
        'expired','kadaluarsa','overdue','jatuh tempo','closed','inactive','nonaktif',
        'deny','denied'
    ];

    function normalize(text){
        return String(text || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function hasAny(text, words){
        return words.some(w => text === w || text.includes(w));
    }

    function applyStatusClass(el){
        const text = normalize(el.textContent);
        if(!text) return;

        el.classList.remove('kp-status-success','kp-status-pending','kp-status-failed');

        if(hasAny(text, successWords)){
            el.classList.add('kp-status-success');
        } else if(hasAny(text, failedWords)){
            el.classList.add('kp-status-failed');
        } else if(hasAny(text, pendingWords)){
            el.classList.add('kp-status-pending');
        }
    }

    function scan(){
        document.querySelectorAll('.badge,[class*="status"],.debt-status,.sr-status,.bundle-status,.payment-status,.transaction-status,.opname-status').forEach(applyStatusClass);
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    // DataTables kadang redraw setelah halaman load.
    document.addEventListener('draw.dt', scan);
    setTimeout(scan, 500);
})();
