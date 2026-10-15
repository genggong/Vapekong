(function(){
    function applyPreview(color){
        document.body.setAttribute('data-theme-color', color || 'red');
    }

    document.addEventListener('change', function(e){
        if(e.target && e.target.name === 'theme_color'){
            applyPreview(e.target.value);
        }
    });
})();
