(() => {
    'use strict';
    const form = document.querySelector('[data-public-signature-form]');
    if (!form) return;
    const canvas = form.querySelector('[data-signature-canvas]');
    const ctx = canvas.getContext('2d', { alpha: true });
    const output = form.querySelector('[data-signature-data]');
    const hint = form.querySelector('[data-signature-hint]');
    const strokes = [];
    let current = null;
    let drawing = false;

    const ratio = () => Math.min(window.devicePixelRatio || 1, 2);
    const drawStroke = (stroke) => {
        if (!stroke?.length) return;
        ctx.beginPath();
        ctx.moveTo(stroke[0].x, stroke[0].y);
        stroke.slice(1).forEach((p) => { ctx.lineWidth = p.w; ctx.lineTo(p.x, p.y); });
        if (stroke.length === 1) ctx.lineTo(stroke[0].x + .1, stroke[0].y + .1);
        ctx.stroke();
    };
    const redraw = () => {
        ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, canvas.width, canvas.height); ctx.restore();
        ctx.strokeStyle = '#102f59'; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        strokes.forEach(drawStroke);
        hint.hidden = strokes.length > 0;
    };
    const resize = () => {
        const rect = canvas.getBoundingClientRect();
        const dpr = ratio();
        canvas.width = Math.max(1, Math.round(rect.width * dpr));
        canvas.height = Math.max(1, Math.round(rect.height * dpr));
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        redraw();
    };
    const point = (event) => {
        const rect = canvas.getBoundingClientRect();
        const pressure = event.pressure > 0 ? event.pressure : .5;
        return { x: event.clientX - rect.left, y: event.clientY - rect.top, w: Math.max(1.8, Math.min(4.4, 1.5 + pressure * 3)) };
    };
    canvas.addEventListener('pointerdown', (event) => {
        drawing = true; current = [point(event)]; strokes.push(current); canvas.setPointerCapture(event.pointerId); redraw();
    });
    canvas.addEventListener('pointermove', (event) => { if (drawing && current) { current.push(point(event)); redraw(); } });
    ['pointerup', 'pointercancel'].forEach((name) => canvas.addEventListener(name, (event) => {
        drawing = false; current = null; if (canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
    }));
    form.querySelector('[data-signature-undo]').addEventListener('click', () => { strokes.pop(); redraw(); });
    form.querySelector('[data-signature-clear]').addEventListener('click', () => { strokes.length = 0; redraw(); });
    form.addEventListener('submit', (event) => {
        if (!strokes.length) { event.preventDefault(); window.alert('Faça sua assinatura antes de concluir.'); return; }
        output.value = canvas.toDataURL('image/png');
    });
    window.addEventListener('resize', resize, { passive: true });
    requestAnimationFrame(resize);
})();
