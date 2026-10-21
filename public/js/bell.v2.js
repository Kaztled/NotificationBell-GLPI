// Keep the previous asset URL usable for pages rendered before the route changed.
(() => {
    const current = document.currentScript;
    if (!current) return;
    const script = document.createElement('script');
    script.src = new URL('bell.v3.js', current.src).href;
    document.head.append(script);
})();
