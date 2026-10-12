/* Early brand paint from localStorage — CSP-safe (no inline). Deferred; keep tiny. */
(function () {
  try {
    var brandRaw = localStorage.getItem('dv-brand-cache-v1');
    if (!brandRaw) return;
    var brand = JSON.parse(brandRaw);
    if (!brand || !brand.branding) return;

    var colors = brand.branding.colors;
    if (colors) {
      var primary = (colors.primary || '').trim();
      if (primary) {
        var style = document.createElement('style');
        style.id = 'cms-brand-tokens';
        style.textContent =
          ':root{--color-brand-600:' + primary + ';--color-brand-700:' + primary + ';}';
        document.head.appendChild(style);
        var metas = document.querySelectorAll('meta[name="theme-color"]');
        for (var i = 0; i < metas.length; i++) metas[i].setAttribute('content', primary);
      }
    }

    var css = (brand.branding.custom_css || '').trim();
    if (css) {
      var custom = document.createElement('style');
      custom.id = 'cms-custom-css';
      custom.textContent = css;
      document.head.appendChild(custom);
    }

    var fav = brand.branding.favicon_url || brand.branding.logo_url || '';
    if (fav && fav.charAt(0) === '/') {
      var link = document.querySelector('link[rel="icon"]');
      if (link) link.setAttribute('href', fav);
    }
  } catch (e) {}
})();
