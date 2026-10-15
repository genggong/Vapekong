(function(){
    function initSidebar(){
        var btn = document.getElementById('sidebarToggle');
        var sidebar = document.getElementById('adminSidebar');
        if(!btn || !sidebar || btn.dataset.rescueReady === '1') return;
        btn.dataset.rescueReady = '1';
        btn.addEventListener('click', function(){
            document.body.classList.toggle('sidebar-open');
            sidebar.classList.toggle('show');
            sidebar.classList.toggle('active');
        });
        document.addEventListener('click', function(e){
            if(window.innerWidth > 991) return;
            if(!document.body.classList.contains('sidebar-open') && !sidebar.classList.contains('show')) return;
            if(sidebar.contains(e.target) || btn.contains(e.target)) return;
            document.body.classList.remove('sidebar-open');
            sidebar.classList.remove('show');
            sidebar.classList.remove('active');
        });
    }
    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
