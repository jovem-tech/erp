(() => {
    'use strict';

    const form = document.querySelector('[data-signature-form]');
    if (!form) return;

    const canvas = form.querySelector('[data-signature-canvas]');
    const context = canvas.getContext('2d', { alpha: true });
    const origin = form.querySelector('[data-signature-origin]');
    const signatureData = form.querySelector('[data-signature-data]');
    const hint = form.querySelector('[data-signature-hint]');
    const fileInput = form.querySelector('[data-signature-file]');
    const strokes = [];
    let currentStroke = null;
    let drawing = false;

    const resizeCanvas = () => {
        const rect = canvas.getBoundingClientRect();
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.max(1, Math.round(rect.width * ratio));
        canvas.height = Math.max(1, Math.round(rect.height * ratio));
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        redraw();
    };

    const redraw = () => {
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        context.save();
        context.setTransform(1, 0, 0, 1, 0, 0);
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.restore();
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = '#102f59';
        strokes.forEach(drawStroke);
        hint.classList.toggle('d-none', strokes.length > 0);
    };

    const drawStroke = (stroke) => {
        if (!stroke || stroke.length === 0) return;
        context.beginPath();
        context.moveTo(stroke[0].x, stroke[0].y);
        stroke.slice(1).forEach((point) => {
            context.lineWidth = point.width;
            context.lineTo(point.x, point.y);
        });
        if (stroke.length === 1) context.lineTo(stroke[0].x + 0.1, stroke[0].y + 0.1);
        context.stroke();
    };

    const pointFrom = (event) => {
        const rect = canvas.getBoundingClientRect();
        const pressure = event.pressure > 0 ? event.pressure : 0.5;
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
            width: Math.max(1.6, Math.min(4.2, 1.4 + pressure * 3)),
        };
    };

    canvas.addEventListener('pointerdown', (event) => {
        if (origin.value !== 'desenho') return;
        drawing = true;
        currentStroke = [pointFrom(event)];
        strokes.push(currentStroke);
        canvas.setPointerCapture(event.pointerId);
        redraw();
    });
    canvas.addEventListener('pointermove', (event) => {
        if (!drawing || !currentStroke) return;
        currentStroke.push(pointFrom(event));
        redraw();
    });
    ['pointerup', 'pointercancel'].forEach((name) => canvas.addEventListener(name, (event) => {
        drawing = false;
        currentStroke = null;
        if (canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
    }));

    form.querySelector('[data-signature-undo]').addEventListener('click', () => { strokes.pop(); redraw(); });
    form.querySelector('[data-signature-clear]').addEventListener('click', () => { strokes.length = 0; redraw(); });

    form.querySelectorAll('[data-signature-mode]').forEach((button) => {
        button.addEventListener('click', () => {
            const mode = button.dataset.signatureMode;
            origin.value = mode;
            form.querySelectorAll('[data-signature-mode]').forEach((item) => {
                const active = item === button;
                item.classList.toggle('btn-primary', active);
                item.classList.toggle('btn-outline-light', !active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            form.querySelectorAll('[data-signature-panel]').forEach((panel) => panel.classList.toggle('d-none', panel.dataset.signaturePanel !== mode));
            fileInput.required = mode === 'upload';
            if (mode === 'desenho') requestAnimationFrame(resizeCanvas);
        });
    });

    form.addEventListener('submit', (event) => {
        if (origin.value === 'desenho') {
            if (strokes.length === 0) {
                event.preventDefault();
                window.alert('Desenhe sua assinatura antes de salvar.');
                return;
            }
            signatureData.value = canvas.toDataURL('image/png');
        } else {
            signatureData.value = '';
            if (!fileInput.files || fileInput.files.length === 0) {
                event.preventDefault();
                window.alert('Selecione a imagem da assinatura.');
            }
        }
    });

    window.addEventListener('resize', resizeCanvas, { passive: true });
    requestAnimationFrame(resizeCanvas);
})();
