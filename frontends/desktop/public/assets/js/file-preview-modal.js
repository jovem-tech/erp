(() => {
    'use strict';

    const modalElement = document.querySelector('[data-file-preview-modal]');
    if (!modalElement || !window.bootstrap) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const title = modalElement.querySelector('[data-file-preview-title]');
    const mime = modalElement.querySelector('[data-file-preview-mime]');
    const type = modalElement.querySelector('[data-file-preview-type]');
    const loading = modalElement.querySelector('[data-file-preview-loading]');
    const error = modalElement.querySelector('[data-file-preview-error]');
    const imageTools = modalElement.querySelector('[data-file-preview-image-tools]');
    const pdfTools = modalElement.querySelector('[data-file-preview-pdf-tools]');
    const imageStage = modalElement.querySelector('[data-file-preview-image-stage]');
    const imageCanvas = modalElement.querySelector('[data-file-preview-image-canvas]');
    const image = modalElement.querySelector('[data-file-preview-image]');
    const frame = modalElement.querySelector('[data-file-preview-frame]');
    const zoomLabel = modalElement.querySelector('[data-file-preview-zoom]');
    const download = modalElement.querySelector('[data-file-preview-download]');
    const fullscreenTarget = modalElement.querySelector('[data-file-preview-fullscreen-target]');

    const state = {
        kind: '',
        previewUrl: '',
        zoom: 1,
        rotation: 0,
        fit: true,
        pointerId: null,
        pointerX: 0,
        pointerY: 0,
        scrollLeft: 0,
        scrollTop: 0,
    };

    const setHidden = (element, hidden) => element?.classList.toggle('d-none', hidden);
    const showLoading = () => {
        setHidden(loading, false);
        setHidden(error, true);
    };
    const showError = () => {
        setHidden(loading, true);
        setHidden(error, false);
    };
    const updateImageTransform = () => {
        if (!image) return;
        image.classList.toggle('is-natural-size', !state.fit);
        image.style.transform = `scale(${state.zoom}) rotate(${state.rotation}deg)`;
        if (zoomLabel) zoomLabel.textContent = `${Math.round(state.zoom * 100)}%`;
    };
    const resetImage = (fit = true) => {
        state.zoom = 1;
        state.rotation = 0;
        state.fit = fit;
        updateImageTransform();
        if (imageStage) {
            imageStage.scrollLeft = 0;
            imageStage.scrollTop = 0;
        }
    };
    const changeZoom = (delta) => {
        state.fit = false;
        state.zoom = Math.min(5, Math.max(.25, Math.round((state.zoom + delta) * 100) / 100));
        updateImageTransform();
    };
    const rotate = (degrees) => {
        state.rotation = (state.rotation + degrees) % 360;
        updateImageTransform();
    };
    const pdfUrl = () => {
        if (!state.previewUrl) return 'about:blank';
        return `${state.previewUrl}#toolbar=1&navpanes=1&view=FitH`;
    };
    const loadPdf = () => {
        if (!frame || !state.previewUrl) return;
        showLoading();
        frame.src = pdfUrl();
    };
    const openViewer = (trigger) => {
        const kind = trigger.dataset.previewKind;
        const previewUrl = trigger.dataset.previewUrl || '';
        const downloadUrl = trigger.dataset.downloadUrl || '';
        const fileName = trigger.dataset.fileName || 'Arquivo';

        if (!['image', 'pdf'].includes(kind) || previewUrl === '' || downloadUrl === '') return;

        state.kind = kind;
        state.previewUrl = previewUrl;
        title.textContent = fileName;
        mime.textContent = trigger.dataset.fileMime || '';
        type.textContent = kind === 'image' ? 'Foto' : 'PDF';
        download.href = downloadUrl;
        download.setAttribute('download', fileName);
        setHidden(imageTools, kind !== 'image');
        setHidden(pdfTools, kind !== 'pdf');
        setHidden(imageStage, kind !== 'image');
        setHidden(frame, kind !== 'pdf');
        showLoading();

        if (kind === 'image') {
            resetImage(true);
            image.alt = `VisualizaÃ§Ã£o de ${fileName}`;
            image.src = previewUrl;
        } else {
            loadPdf();
        }

        modal.show(trigger);
    };
    const clearViewer = () => {
        state.kind = '';
        state.previewUrl = '';
        state.pointerId = null;
        if (image) {
            image.removeAttribute('src');
            image.alt = '';
            image.style.transform = '';
        }
        if (frame) frame.src = 'about:blank';
        if (download) {
            download.href = '#';
            download.removeAttribute('download');
        }
        imageStage?.classList.remove('is-panning');
        setHidden(imageStage, true);
        setHidden(frame, true);
        setHidden(imageTools, true);
        setHidden(pdfTools, true);
        showLoading();
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-file-preview-trigger]');
        if (trigger) {
            event.preventDefault();
            openViewer(trigger);
            return;
        }

        const actionButton = event.target.closest('[data-file-preview-action]');
        if (!actionButton || !modalElement.contains(actionButton)) return;

        switch (actionButton.dataset.filePreviewAction) {
            case 'zoom-out': changeZoom(-.25); break;
            case 'zoom-in': changeZoom(.25); break;
            case 'reset': resetImage(false); break;
            case 'fit': resetImage(true); break;
            case 'rotate-left': rotate(-90); break;
            case 'rotate-right': rotate(90); break;
            case 'reload': loadPdf(); break;
            case 'fullscreen':
                if (document.fullscreenElement) document.exitFullscreen?.();
                else fullscreenTarget?.requestFullscreen?.();
                break;
            default: break;
        }
    });

    image?.addEventListener('load', () => {
        setHidden(loading, true);
        setHidden(error, true);
        imageStage?.focus({ preventScroll: true });
    });
    image?.addEventListener('error', showError);
    frame?.addEventListener('load', () => {
        if (frame.src !== 'about:blank') setHidden(loading, true);
    });

    imageStage?.addEventListener('wheel', (event) => {
        if (!event.ctrlKey) return;
        event.preventDefault();
        changeZoom(event.deltaY < 0 ? .25 : -.25);
    }, { passive: false });
    imageStage?.addEventListener('keydown', (event) => {
        if (state.kind !== 'image') return;
        const actions = {
            '+': () => changeZoom(.25),
            '=': () => changeZoom(.25),
            '-': () => changeZoom(-.25),
            '0': () => resetImage(true),
            'r': () => rotate(90),
            'R': () => rotate(-90),
        };
        if (!actions[event.key]) return;
        event.preventDefault();
        actions[event.key]();
    });

    imageStage?.addEventListener('pointerdown', (event) => {
        if (state.kind !== 'image' || state.fit) return;
        state.pointerId = event.pointerId;
        state.pointerX = event.clientX;
        state.pointerY = event.clientY;
        state.scrollLeft = imageStage.scrollLeft;
        state.scrollTop = imageStage.scrollTop;
        imageStage.setPointerCapture(event.pointerId);
        imageStage.classList.add('is-panning');
    });
    imageStage?.addEventListener('pointermove', (event) => {
        if (state.pointerId !== event.pointerId) return;
        imageStage.scrollLeft = state.scrollLeft - (event.clientX - state.pointerX);
        imageStage.scrollTop = state.scrollTop - (event.clientY - state.pointerY);
    });
    const stopPanning = (event) => {
        if (state.pointerId !== event.pointerId) return;
        state.pointerId = null;
        imageStage?.classList.remove('is-panning');
    };
    imageStage?.addEventListener('pointerup', stopPanning);
    imageStage?.addEventListener('pointercancel', stopPanning);

    modalElement.addEventListener('hidden.bs.modal', clearViewer);
})();
