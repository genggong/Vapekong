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
        'deny','denied','refunded','refund'
    ];

    function norm(s){
        return String(s || '')
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function matchAny(text, arr){
        return arr.some(function(w){
            return text === w || text.indexOf(w) !== -1;
        });
    }

    function typeOfStatus(text){
        text = norm(text);
        if(!text) return '';
        if(matchAny(text, successWords)) return 'success';
        if(matchAny(text, failedWords)) return 'failed';
        if(matchAny(text, pendingWords)) return 'pending';
        return '';
    }

    function isLikelyBadge(el){
        if(!el || !el.textContent) return false;
        const txt = norm(el.textContent);
        if(txt.length < 2 || txt.length > 32) return false;

        const cls = String(el.className || '').toLowerCase();
        if(
            cls.includes('badge') ||
            cls.includes('status') ||
            cls.includes('label') ||
            cls.includes('pill') ||
            cls.includes('tag')
        ) return true;

        // Kolom status di DataTables: span kecil di dalam td.
        const td = el.closest('td');
        if(td){
            const thIndex = Array.from(td.parentNode.children).indexOf(td);
            const table = td.closest('table');
            if(table && thIndex >= 0){
                const th = table.querySelector('thead tr th:nth-child(' + (thIndex + 1) + ')');
                const thText = norm(th ? th.textContent : '');
                if(thText.includes('status')) return true;
            }
        }

        return false;
    }

    function force(el, type){
        el.classList.remove('kp-status-success','kp-status-pending','kp-status-failed','kp-force-success','kp-force-pending','kp-force-failed');
        el.classList.add('kp-force-status');

        if(type === 'success'){
            el.classList.add('kp-force-success');
            el.style.setProperty('background', '#dcfce7', 'important');
            el.style.setProperty('color', '#166534', 'important');
            el.style.setProperty('border-color', '#86efac', 'important');
        } else if(type === 'pending'){
            el.classList.add('kp-force-pending');
            el.style.setProperty('background', '#fef3c7', 'important');
            el.style.setProperty('color', '#92400e', 'important');
            el.style.setProperty('border-color', '#fcd34d', 'important');
        } else if(type === 'failed'){
            el.classList.add('kp-force-failed');
            el.style.setProperty('background', '#fee2e2', 'important');
            el.style.setProperty('color', '#991b1b', 'important');
            el.style.setProperty('border-color', '#fecaca', 'important');
        }
    }

    function scan(root){
        root = root || document;

        const selectors = [
            '.badge',
            '[class*="status"]',
            '[class*="Status"]',
            '[class*="badge"]',
            '[class*="Badge"]',
            '[class*="pill"]',
            '[class*="Pill"]',
            '[class*="label"]',
            '[class*="Label"]',
            'td span',
            'td small',
            'td b'
        ].join(',');

        root.querySelectorAll(selectors).forEach(function(el){
            if(!isLikelyBadge(el)) return;
            const type = typeOfStatus(el.textContent);
            if(type) force(el, type);
        });
    }

    function schedule(){
        scan(document);
        setTimeout(function(){ scan(document); }, 100);
        setTimeout(function(){ scan(document); }, 500);
        setTimeout(function(){ scan(document); }, 1200);
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', schedule);
    } else {
        schedule();
    }

    // DataTables redraw support.
    if(window.jQuery){
        jQuery(document).on('draw.dt', function(){ setTimeout(schedule, 30); });
    }
    document.addEventListener('draw.dt', function(){ setTimeout(schedule, 30); });

    // Observe dynamic content (DataTables/AJAX).
    const observer = new MutationObserver(function(mutations){
        let shouldScan = false;
        mutations.forEach(function(m){
            if(m.addedNodes && m.addedNodes.length) shouldScan = true;
        });
        if(shouldScan) setTimeout(function(){ scan(document); }, 80);
    });
    observer.observe(document.documentElement, {childList:true, subtree:true});
})();
