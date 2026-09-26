/**
 * Padrão único de inserção de imagem do desktop (specs/049). TODA tela que
 * recebe imagem usa esta biblioteca — nunca um <input type="file"> próprio:
 *
 * - origens: Câmera (webcam num modal com várias capturas e troca de câmera;
 *   em celular/tablet, a câmera nativa), Computador/galeria, Colar (botão e
 *   Ctrl+V fora de campo de texto) e arrastar;
 * - recorte OPCIONAL em cada imagem (girar, ampliar, restaurar);
 * - redução no navegador (lado máximo 2560 px) só como economia de tráfego —
 *   o backend continua sendo quem valida e otimiza;
 * - formato e tamanho do destino conferidos antes de enviar.
 *
 * Três formas de usar, da mais declarativa à mais livre:
 * - data-image-picker="field" (<x-image-picker.field>): campo de formulário;
 *   a imagem vai para o <input type="file" name=...> do form via DataTransfer,
 *   então a rota que recebe o form não muda;
 * - data-image-picker="uploader" (<x-image-picker.queue>): fila com "Enviar"
 *   em lotes por XHR; o JSON de resposta traz `html` que substitui a galeria;
 * - ErpImagePicker.attach(root, { onFiles }): só as origens, para telas com
 *   regra própria (foto principal do equipamento, fotos da Nova OS).
 */
(function () {
    'use strict';

    if (window.ErpImagePicker) {
        return;
    }

    const SCRIPT_SRC = document.currentScript instanceof HTMLScriptElement ? document.currentScript.src : '';
    const ASSETS_BASE = SCRIPT_SRC !== '' ? SCRIPT_SRC.replace(/js\/image-picker\.js(\?.*)?$/, '') : '/assets/';

    const MB = 1024 * 1024;
    const EXTENSIONS_BY_TYPE = {
        'image/jpeg': ['jpg', 'jpeg'],
        'image/png': ['png'],
        'image/webp': ['webp'],
        'image/avif': ['avif'],
        'image/heic': ['heic', 'heif'],
        'image/heif': ['heif', 'heic'],
        'application/pdf': ['pdf'],
    };
    const TYPE_BY_EXTENSION = {
        jpg: 'image/jpeg',
        jpeg: 'image/jpeg',
        png: 'image/png',
        webp: 'image/webp',
        avif: 'image/avif',
        heic: 'image/heic',
        heif: 'image/heif',
        pdf: 'application/pdf',
    };
    const TYPE_LABELS = {
        'image/jpeg': 'JPEG',
        'image/png': 'PNG',
        'image/webp': 'WebP',
        'image/avif': 'AVIF',
        'image/heic': 'HEIC',
        'image/heif': 'HEIF',
        'application/pdf': 'PDF',
    };
    // Espelham os destinos reais: fotos operacionais (OS/equipamento, specs/046),
    // imagens de cadastro (perfil, logo, fundo, assinatura) e anexos (+ PDF).
    const PRESETS = {
        photo: { types: ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic', 'image/heif'], maxBytes: 20 * MB },
        image: { types: ['image/jpeg', 'image/png', 'image/webp'], maxBytes: 4 * MB },
        document: { types: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], maxBytes: 20 * MB },
    };
    // Tipos que o canvas decodifica em qualquer navegador suportado. AVIF/HEIC
    // seguem originais: o servidor converte.
    const REENCODABLE = ['image/jpeg', 'image/png', 'image/webp'];
    const CROPPABLE = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
    const MAX_SIDE = 2560;
    const DOWNSCALE_ABOVE = 1.5 * MB;
    const MAX_DECODE_PIXELS = 60000000;
    const JPEG_QUALITIES = [0.9, 0.82, 0.74, 0.66];
    const SOURCE_ICONS = { camera: 'bi-camera', clipboard: 'bi-clipboard', file: 'bi-image', crop: 'bi-crop' };
    const CAMERA_DEVICE_KEY = 'erp.imagePicker.cameraDevice';
    const IS_APPLE = /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
    const PASTE_SHORTCUT = IS_APPLE ? '⌘V' : 'Ctrl+V';

    let nameSeq = 0;
    let itemSeq = 0;

    // ── utilidades ──────────────────────────────────────────────────────────

    const toast = (icon, title) => {
        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({
            toast: true,
            position: 'top-end',
            timer: icon === 'error' ? 6000 : 4000,
            timerProgressBar: true,
            showConfirmButton: false,
            icon,
            title,
            customClass: { popup: 'swal-desktop-toast' },
        });
    };

    const plural = (count, singular, pluralForm) => `${count} ${count === 1 ? singular : pluralForm}`;

    const formatBytes = (bytes) => {
        if (bytes >= MB) {
            return `${(bytes / MB).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
        }

        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    };

    const stamp = () => {
        const now = new Date();
        const pad = (value) => String(value).padStart(2, '0');

        return `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;
    };

    const extensionOf = (name) => {
        const match = /\.([a-z0-9]+)$/i.exec(String(name || ''));

        return match ? match[1].toLowerCase() : '';
    };

    /** MIME real ou, quando o SO não informa (HEIC no Windows), o da extensão. */
    const typeOf = (file) => {
        const type = String(file?.type || '').toLowerCase();
        if (type !== '' && type !== 'application/octet-stream') {
            return type === 'image/jpg' ? 'image/jpeg' : type;
        }

        return TYPE_BY_EXTENSION[extensionOf(file?.name)] || type;
    };

    const typesLabel = (types) => {
        const labels = [...new Set(types.map((type) => TYPE_LABELS[type] || type))];

        return labels.length > 1 ? `${labels.slice(0, -1).join(', ')} ou ${labels[labels.length - 1]}` : labels.join('');
    };

    const isEditableTarget = (target) => target instanceof Element
        && target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]') !== null;

    const isVisible = (element) => element instanceof HTMLElement && element.getClientRects().length > 0;

    const resolveOptions = (raw = {}) => {
        const accept = Object.prototype.hasOwnProperty.call(PRESETS, raw.accept) ? raw.accept : 'photo';
        const preset = PRESETS[accept];
        const multiple = raw.multiple !== false;
        const maxFiles = Number(raw.maxFiles) > 0 ? Number(raw.maxFiles) : (multiple ? 20 : 1);

        return {
            ...raw,
            accept,
            types: Array.isArray(raw.types) && raw.types.length > 0 ? raw.types : preset.types,
            maxBytes: Number(raw.maxBytes) > 0 ? Number(raw.maxBytes) : preset.maxBytes,
            maxSide: Number(raw.maxSide) > 0 ? Number(raw.maxSide) : MAX_SIDE,
            keepPng: raw.keepPng === true,
            multiple: multiple && maxFiles > 1,
            maxFiles,
            paste: raw.paste === 'page' ? 'page' : 'focus',
            cropRatio: Number(raw.cropRatio) > 0 ? Number(raw.cropRatio) : NaN,
        };
    };

    /** Opções declaradas em data-image-picker-* no elemento raiz. */
    const optionsFromDataset = (root) => {
        const data = root.dataset;
        const options = {};
        if (data.imagePickerAccept) options.accept = data.imagePickerAccept;
        if (data.imagePickerMax) options.maxFiles = Number(data.imagePickerMax);
        if (data.imagePickerMaxBytes) options.maxBytes = Number(data.imagePickerMaxBytes);
        if (data.imagePickerMaxSide) options.maxSide = Number(data.imagePickerMaxSide);
        if (data.imagePickerCropRatio) options.cropRatio = Number(data.imagePickerCropRatio);
        if (data.imagePickerPaste) options.paste = data.imagePickerPaste;
        if (data.imagePickerKeepPng === 'true') options.keepPng = true;
        if (data.imagePickerMultiple === 'false') options.multiple = false;

        return options;
    };

    /**
     * O backend recusa extensão que não bate com o conteúdo. Imagem colada chega
     * como "image.png" (todas com o mesmo nome). Dá nome legível e extensão
     * coerente com o MIME real.
     */
    const withSafeName = (file, prefix) => {
        const type = typeOf(file);
        const allowed = EXTENSIONS_BY_TYPE[type] || null;
        const currentName = String(file.name || '').trim();
        const genericName = /^(image|imagem|blob|screenshot)?(\.[a-z0-9]+)?$/i.test(currentName);

        if (!allowed || (!genericName && allowed.includes(extensionOf(currentName)))) {
            return file;
        }

        nameSeq += 1;
        const baseName = genericName ? `${prefix}-${stamp()}-${nameSeq}` : currentName.replace(/\.[^.]*$/, '');

        return new File([file], `${baseName}.${allowed[0]}`, { type, lastModified: Date.now() });
    };

    const loadImage = (blob) => new Promise((resolve) => {
        const url = URL.createObjectURL(blob);
        const image = new Image();
        image.onload = () => resolve({ image, url });
        image.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(null);
        };
        image.src = url;
    });

    const canvasToBlob = (canvas, type, quality) => new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), type, quality);
    });

    const drawScaled = (source, sourceWidth, sourceHeight, maxSide, opaque) => {
        const scale = Math.min(1, maxSide / Math.max(sourceWidth, sourceHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(sourceWidth * scale));
        canvas.height = Math.max(1, Math.round(sourceHeight * scale));
        const context = canvas.getContext('2d');
        if (!context) {
            return null;
        }

        if (opaque) {
            // Fundo branco: transparência viraria preto no JPEG.
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
        }
        context.imageSmoothingQuality = 'high';
        context.drawImage(source, 0, 0, canvas.width, canvas.height);

        return canvas;
    };

    /**
     * Codifica a imagem no menor lado/qualidade que ainda cabe no limite do
     * destino. PNG com `keepPng` (logo, assinatura) continua PNG — só reduz o
     * lado. Devolve null se nada couber.
     */
    const encodeFitting = async (source, width, height, options, asPng) => {
        const sides = [...new Set([options.maxSide, 2048, 1600, 1280, 1024].filter((side) => side <= options.maxSide))];
        let smallest = null;

        for (const side of sides) {
            const canvas = drawScaled(source, width, height, side, !asPng);
            if (!canvas) {
                return null;
            }

            const attempts = asPng ? [['image/png', undefined]] : JPEG_QUALITIES.map((quality) => ['image/jpeg', quality]);
            for (const [type, quality] of attempts) {
                // eslint-disable-next-line no-await-in-loop -- tentativas em sequência, param no primeiro que cabe
                const blob = await canvasToBlob(canvas, type, quality);
                if (blob instanceof Blob && (smallest === null || blob.size < smallest.size)) {
                    smallest = blob;
                }
                if (blob instanceof Blob && blob.size <= Math.min(options.maxBytes, DOWNSCALE_ABOVE * 2)) {
                    canvas.width = 0;
                    canvas.height = 0;
                    return blob;
                }
            }
            canvas.width = 0;
            canvas.height = 0;
        }

        return smallest !== null && smallest.size <= options.maxBytes ? smallest : null;
    };

    const renamed = (file, blob, suffix = '') => {
        const extension = blob.type === 'image/png' ? 'png' : 'jpg';
        const baseName = String(file.name || `imagem-${stamp()}`).replace(/\.[^.]*$/, '');

        return new File([blob], `${baseName}${suffix}.${extension}`, { type: blob.type, lastModified: Date.now() });
    };

    /**
     * Reduz só quando vale a pena (arquivo grande ou acima do limite do
     * destino). Qualquer falha devolve o original — reduzir é economia de
     * tráfego, nunca condição para enviar.
     */
    const downscaleIfUseful = async (file, options) => {
        const type = typeOf(file);
        if (!REENCODABLE.includes(type) || (file.size <= DOWNSCALE_ABOVE && file.size <= options.maxBytes)) {
            return file;
        }

        const loaded = await loadImage(file);
        if (!loaded) {
            return file;
        }

        const { image, url } = loaded;
        try {
            const width = image.naturalWidth;
            const height = image.naturalHeight;
            if (width <= 0 || height <= 0 || width * height > MAX_DECODE_PIXELS) {
                return file;
            }

            const blob = await encodeFitting(image, width, height, options, options.keepPng && type === 'image/png');
            if (!(blob instanceof Blob) || (blob.size >= file.size && file.size <= options.maxBytes)) {
                return file;
            }

            return renamed(file, blob);
        } catch (error) {
            console.warn('[image-picker] Redução no navegador falhou; seguindo com o original.', error);

            return file;
        } finally {
            URL.revokeObjectURL(url);
        }
    };

    /**
     * Confere formato e tamanho do destino, normaliza o nome e reduz o que
     * precisa. Uma por vez: decodificar várias fotos de 12 MP juntas estoura a
     * memória de celular modesto.
     */
    const prepare = async (incoming, rawOptions = {}, source = 'file') => {
        const options = rawOptions.types ? rawOptions : resolveOptions(rawOptions);
        const accepted = [];
        const rejected = [];
        const prefix = source === 'camera' ? 'camera' : (source === 'clipboard' ? 'colada' : 'imagem');

        for (const original of Array.from(incoming || [])) {
            if (!(original instanceof File)) {
                continue;
            }

            const name = original.name || 'Arquivo';
            if (!options.types.includes(typeOf(original))) {
                rejected.push(`${name}: formato não aceito aqui (use ${typesLabel(options.types)}).`);
                continue;
            }
            if (original.size <= 0) {
                rejected.push(`${name}: arquivo vazio.`);
                continue;
            }

            // eslint-disable-next-line no-await-in-loop -- sequencial de propósito (memória)
            const prepared = await downscaleIfUseful(withSafeName(original, prefix), options);
            if (prepared.size > options.maxBytes) {
                rejected.push(`${name}: passa de ${formatBytes(options.maxBytes)}.`);
                continue;
            }

            accepted.push(prepared);
        }

        return { files: accepted, rejected };
    };

    const reportRejected = (rejected) => {
        if (rejected.length === 0) {
            return;
        }

        toast('warning', rejected.length === 1
            ? rejected[0]
            : `${plural(rejected.length, 'arquivo não foi aceito', 'arquivos não foram aceitos')}. ${rejected[0]}`);
    };

    const filesFrom = (dataTransfer) => {
        if (!dataTransfer) {
            return [];
        }

        const files = Array.from(dataTransfer.files || []);
        if (files.length > 0) {
            return files;
        }

        return Array.from(dataTransfer.items || [])
            .filter((item) => item.kind === 'file')
            .map((item) => item.getAsFile())
            .filter((file) => file instanceof File);
    };

    const hasDraggedFiles = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');

    const modal = (element) => (window.bootstrap?.Modal ? window.bootstrap.Modal.getOrCreateInstance(element) : null);

    const loadScript = (src) => new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = () => reject(new Error(`Falha ao carregar ${src}`));
        document.head.appendChild(script);
    });

    const ensureStylesheet = (href) => {
        if (document.querySelector('link[href*="cropper.min.css"]')) {
            return;
        }
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        document.head.appendChild(link);
    };

    let cropperLoading = null;
    const loadCropper = () => {
        ensureStylesheet(`${ASSETS_BASE}libs/cropperjs/cropper.min.css`);
        if (window.Cropper) {
            return Promise.resolve(window.Cropper);
        }
        cropperLoading = cropperLoading || loadScript(`${ASSETS_BASE}libs/cropperjs/cropper.min.js`).then(() => window.Cropper);

        return cropperLoading;
    };

    // ── recorte (opcional) ──────────────────────────────────────────────────

    let cropElements = null;
    let cropSession = null;

    const buildCropModal = () => {
        if (cropElements) {
            return cropElements;
        }

        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="erpImageCropModal" tabindex="-1" aria-labelledby="erpImageCropTitle" aria-hidden="true" data-bs-backdrop="static">
                <div class="modal-dialog modal-dialog-centered modal-xl">
                    <div class="modal-content modal-shell">
                        <div class="modal-header">
                            <div>
                                <h2 class="modal-title fs-5" id="erpImageCropTitle"><i class="bi bi-crop me-2"></i>Recortar imagem</h2>
                                <p class="surface-subtitle mb-0">Arraste e ajuste a área que deve ficar. Recortar é opcional.</p>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancelar recorte"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex flex-wrap gap-2 mb-3" role="toolbar" aria-label="Ferramentas do recorte">
                                <button type="button" class="btn btn-outline-light btn-sm" data-crop-action="rotate-left"><i class="bi bi-arrow-counterclockwise me-1"></i>Girar à esquerda</button>
                                <button type="button" class="btn btn-outline-light btn-sm" data-crop-action="rotate-right"><i class="bi bi-arrow-clockwise me-1"></i>Girar à direita</button>
                                <button type="button" class="btn btn-outline-light btn-sm" data-crop-action="zoom-in"><i class="bi bi-zoom-in me-1"></i>Ampliar</button>
                                <button type="button" class="btn btn-outline-light btn-sm" data-crop-action="zoom-out"><i class="bi bi-zoom-out me-1"></i>Reduzir</button>
                                <button type="button" class="btn btn-outline-light btn-sm" data-crop-action="reset"><i class="bi bi-arrow-repeat me-1"></i>Restaurar</button>
                            </div>
                            <div class="image-picker-crop-stage"><img alt="Imagem para recortar" data-crop-image></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" data-crop-confirm disabled><i class="bi bi-check2-circle me-2"></i>Usar recorte</button>
                        </div>
                    </div>
                </div>
            </div>`;
        const element = wrapper.firstElementChild;
        document.body.appendChild(element);

        cropElements = {
            modal: element,
            image: element.querySelector('[data-crop-image]'),
            confirm: element.querySelector('[data-crop-confirm]'),
        };

        element.querySelectorAll('[data-crop-action]').forEach((button) => button.addEventListener('click', () => {
            const cropper = cropSession?.cropper;
            if (!cropper) {
                return;
            }
            const action = button.dataset.cropAction;
            if (action === 'rotate-left') cropper.rotate(-90);
            if (action === 'rotate-right') cropper.rotate(90);
            if (action === 'zoom-in') cropper.zoom(0.1);
            if (action === 'zoom-out') cropper.zoom(-0.1);
            if (action === 'reset') cropper.reset();
        }));

        cropElements.confirm.addEventListener('click', async () => {
            const session = cropSession;
            if (!session?.cropper || session.saving) {
                return;
            }

            session.saving = true;
            cropElements.confirm.disabled = true;
            try {
                const asPng = session.options.keepPng && typeOf(session.file) === 'image/png';
                const canvas = session.cropper.getCroppedCanvas({
                    maxWidth: 8192,
                    maxHeight: 8192,
                    fillColor: asPng ? 'transparent' : '#ffffff',
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high',
                });
                const blob = canvas instanceof HTMLCanvasElement
                    ? await encodeFitting(canvas, canvas.width, canvas.height, session.options, asPng)
                    : null;
                if (!(blob instanceof Blob)) {
                    toast('warning', `O recorte ficou maior que ${formatBytes(session.options.maxBytes)}. Reduza a área e tente de novo.`);
                    return;
                }
                session.result = renamed(session.file, blob, /-recorte$/.test(session.file.name.replace(/\.[^.]*$/, '')) ? '' : '-recorte');
                modal(cropElements.modal)?.hide();
            } catch (error) {
                console.error('[image-picker] Falha ao recortar', error);
                toast('error', 'Não foi possível recortar a imagem.');
            } finally {
                session.saving = false;
                cropElements.confirm.disabled = !session.cropper;
            }
        });

        element.addEventListener('hidden.bs.modal', () => {
            const session = cropSession;
            cropSession = null;
            session?.cropper?.destroy?.();
            if (session?.url) {
                URL.revokeObjectURL(session.url);
            }
            cropElements.image.removeAttribute('src');
            session?.resolve(session.result || null);
        });

        return cropElements;
    };

    const canCrop = (file) => CROPPABLE.includes(typeOf(file));

    /** Abre o editor de recorte; resolve com o arquivo recortado ou null (cancelou). */
    const crop = async (file, rawOptions = {}) => {
        if (!(file instanceof File) || !canCrop(file) || cropSession || !window.bootstrap?.Modal) {
            return null;
        }

        const options = rawOptions.types ? rawOptions : resolveOptions(rawOptions);
        let Cropper = null;
        try {
            Cropper = await loadCropper();
        } catch (error) {
            toast('error', 'O editor de recorte não carregou. Atualize a página e tente de novo.');
            return null;
        }

        const elements = buildCropModal();

        return new Promise((resolve) => {
            const session = { file, options, resolve, cropper: null, url: URL.createObjectURL(file), result: null, saving: false };
            cropSession = session;
            elements.confirm.disabled = true;

            elements.image.onload = () => {
                if (cropSession !== session) {
                    return;
                }
                if (elements.image.naturalWidth * elements.image.naturalHeight > MAX_DECODE_PIXELS) {
                    toast('warning', 'Imagem grande demais para recortar no navegador (acima de 60 megapixels).');
                    modal(elements.modal)?.hide();
                    return;
                }
                session.cropper = new Cropper(elements.image, {
                    viewMode: 1,
                    autoCropArea: 1,
                    background: false,
                    responsive: true,
                    // Sem XHR no blob: para ler EXIF — a CSP (connect-src) não
                    // libera blob:, e os navegadores atuais já giram a foto pela
                    // orientação EXIF no <img> e no drawImage.
                    checkOrientation: false,
                    aspectRatio: options.cropRatio,
                });
                elements.confirm.disabled = false;
            };
            elements.image.onerror = () => {
                if (cropSession !== session || !elements.image.getAttribute('src')) {
                    return;
                }
                toast('info', 'Esta imagem não abre no editor. Ela segue sem recorte e o servidor converte.');
                modal(elements.modal)?.hide();
            };

            modal(elements.modal)?.show();
            elements.image.src = session.url;
        });
    };

    // ── câmera ──────────────────────────────────────────────────────────────

    let cameraElements = null;
    const camera = {
        open: false,
        stream: null,
        generation: 0,
        shots: 0,
        captureSeq: 0,
        shotUrls: [],
        single: false,
        onCapture: null,
    };

    const cameraErrorMessage = (error) => {
        switch (error && error.name) {
            case 'NotAllowedError':
            case 'PermissionDeniedError':
            case 'SecurityError':
                return 'O acesso à câmera foi bloqueado. Libere a câmera no ícone da barra de endereço do navegador e tente de novo.';
            case 'NotFoundError':
            case 'DevicesNotFoundError':
            case 'OverconstrainedError':
                return 'Nenhuma câmera foi encontrada neste computador. Use "Computador / galeria".';
            case 'NotReadableError':
            case 'TrackStartError':
            case 'AbortError':
                return 'A câmera está em uso por outro programa (Teams, Zoom, WhatsApp…). Feche-o e tente de novo.';
            default:
                return 'Não foi possível abrir a câmera. Use "Computador / galeria".';
        }
    };

    const readSavedDevice = () => {
        try {
            return window.localStorage.getItem(CAMERA_DEVICE_KEY) || '';
        } catch (error) {
            return '';
        }
    };

    const saveDevice = (deviceId) => {
        try {
            if (deviceId) {
                window.localStorage.setItem(CAMERA_DEVICE_KEY, deviceId);
            } else {
                window.localStorage.removeItem(CAMERA_DEVICE_KEY);
            }
        } catch (error) {
            // Armazenamento bloqueado: só não lembra a câmera escolhida.
        }
    };

    const buildCameraModal = () => {
        if (cameraElements) {
            return cameraElements;
        }

        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="erpImageCameraModal" tabindex="-1" aria-labelledby="erpImageCameraTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content modal-shell">
                        <div class="modal-header gap-2">
                            <h5 class="modal-title me-auto" id="erpImageCameraTitle"><i class="bi bi-camera me-2"></i>Tirar fotos</h5>
                            <select class="form-select form-select-sm image-picker-select image-picker-camera-device d-none" aria-label="Câmera" data-select2="false" data-camera-device></select>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="image-picker-camera-stage">
                                <video autoplay playsinline muted data-camera-video></video>
                                <div class="image-picker-camera-flash" data-camera-flash></div>
                                <div class="image-picker-camera-status" data-camera-status>Abrindo a câmera…</div>
                            </div>
                            <div class="image-picker-camera-shots" data-camera-shots></div>
                        </div>
                        <div class="modal-footer">
                            <small class="me-auto text-secondary" data-camera-count aria-live="polite"></small>
                            <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal" data-camera-done>Concluir</button>
                            <button type="button" class="btn btn-primary" data-camera-shoot disabled><i class="bi bi-camera me-1"></i>Capturar</button>
                        </div>
                    </div>
                </div>
            </div>`;
        const element = wrapper.firstElementChild;
        document.body.appendChild(element);

        cameraElements = {
            modal: element,
            title: element.querySelector('#erpImageCameraTitle'),
            video: element.querySelector('[data-camera-video]'),
            device: element.querySelector('[data-camera-device]'),
            shoot: element.querySelector('[data-camera-shoot]'),
            done: element.querySelector('[data-camera-done]'),
            status: element.querySelector('[data-camera-status]'),
            count: element.querySelector('[data-camera-count]'),
            shots: element.querySelector('[data-camera-shots]'),
            flash: element.querySelector('[data-camera-flash]'),
        };

        cameraElements.shoot.addEventListener('click', captureFrame);
        cameraElements.device.addEventListener('change', () => {
            const deviceId = cameraElements.device.value;
            saveDevice(deviceId);
            startStream(deviceId);
        });
        element.addEventListener('hidden.bs.modal', () => {
            camera.open = false;
            stopStream();
            camera.shotUrls.forEach((url) => URL.revokeObjectURL(url));
            camera.shotUrls = [];
            cameraElements.shots.replaceChildren();
            const afterClose = camera.afterClose;
            camera.afterClose = null;
            camera.onCapture = null;
            afterClose?.(camera.shots);
        });

        return cameraElements;
    };

    const setCameraStatus = (message) => {
        cameraElements.status.textContent = message;
        cameraElements.status.classList.toggle('d-none', message === '');
    };

    const updateCameraCount = () => {
        cameraElements.count.textContent = camera.single
            ? ''
            : (camera.shots === 0 ? 'Nenhuma foto capturada.' : `${plural(camera.shots, 'foto capturada', 'fotos capturadas')} — já na fila.`);
    };

    const stopStream = () => {
        if (camera.stream) {
            camera.stream.getTracks().forEach((track) => track.stop());
            camera.stream = null;
        }
        if (cameraElements) {
            cameraElements.video.srcObject = null;
            cameraElements.shoot.disabled = true;
        }
    };

    const populateDevices = async (activeDeviceId) => {
        if (typeof navigator.mediaDevices.enumerateDevices !== 'function') {
            return;
        }

        try {
            const cameras = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === 'videoinput');
            if (cameras.length < 2) {
                cameraElements.device.classList.add('d-none');
                return;
            }

            cameraElements.device.replaceChildren(...cameras.map((device, index) => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.textContent = device.label || `Câmera ${index + 1}`;
                option.selected = device.deviceId === activeDeviceId;
                return option;
            }));
            cameraElements.device.classList.remove('d-none');
        } catch (error) {
            cameraElements.device.classList.add('d-none');
        }
    };

    const startStream = async (deviceId) => {
        stopStream();
        setCameraStatus('Abrindo a câmera…');
        // Fechar e reabrir (ou trocar de câmera) enquanto o navegador ainda pede
        // permissão deixaria dois pedidos em voo; só o último pode ficar com a
        // câmera, senão a luz da webcam fica acesa sem ninguém usando.
        camera.generation += 1;
        const generation = camera.generation;

        const video = { width: { ideal: 1920 }, height: { ideal: 1080 } };
        if (deviceId) {
            video.deviceId = { exact: deviceId };
        } else {
            video.facingMode = { ideal: 'environment' };
        }

        let stream = null;
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video, audio: false });
        } catch (error) {
            if (generation !== camera.generation) {
                return;
            }
            if (deviceId && (error.name === 'OverconstrainedError' || error.name === 'NotFoundError')) {
                // A câmera lembrada foi desconectada: volta para a padrão.
                saveDevice('');
                startStream('');
                return;
            }

            console.warn('[image-picker] Falha ao abrir a câmera', error);
            setCameraStatus(cameraErrorMessage(error));
            return;
        }

        if (!camera.open || generation !== camera.generation) {
            stream.getTracks().forEach((track) => track.stop());
            return;
        }

        camera.stream = stream;
        cameraElements.video.srcObject = stream;
        try {
            await cameraElements.video.play();
        } catch (error) {
            // autoplay já cobre; play() só falha se o modal fechou no meio.
        }

        const activeDeviceId = stream.getVideoTracks()[0]?.getSettings?.().deviceId || deviceId || '';
        setCameraStatus('');
        cameraElements.shoot.disabled = false;
        cameraElements.shoot.focus();
        await populateDevices(activeDeviceId);
    };

    function captureFrame() {
        const video = cameraElements.video;
        if (!camera.stream || video.videoWidth === 0) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }
        context.drawImage(video, 0, 0, canvas.width, canvas.height);

        cameraElements.flash.classList.remove('is-flashing');
        void cameraElements.flash.offsetWidth;
        cameraElements.flash.classList.add('is-flashing');

        canvas.toBlob((blob) => {
            canvas.width = 0;
            canvas.height = 0;
            if (!(blob instanceof Blob)) {
                toast('error', 'Não foi possível gerar a imagem da câmera.');
                return;
            }

            camera.captureSeq += 1;
            const file = new File([blob], `camera-${stamp()}-${camera.captureSeq}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
            const onCapture = camera.onCapture;

            if (camera.single) {
                camera.shots += 1;
                modal(cameraElements.modal)?.hide();
                onCapture?.(file);
                return;
            }

            // Só conta (e mostra na tira) o que o destino aceitou — acima do
            // limite de fotos a captura é recusada e não pode aparecer como "na fila".
            Promise.resolve(onCapture?.(file)).then((added) => {
                if (!(Number(added) > 0) || !camera.open) {
                    return;
                }
                camera.shots += 1;
                const url = URL.createObjectURL(blob);
                camera.shotUrls.push(url);
                const thumb = document.createElement('img');
                thumb.src = url;
                thumb.alt = `Captura ${camera.shots}`;
                cameraElements.shots.appendChild(thumb);
                updateCameraCount();
            });
        }, 'image/jpeg', 0.92);
    }

    const webcamAvailable = () => Boolean(
        window.bootstrap?.Modal
        && navigator.mediaDevices
        && typeof navigator.mediaDevices.getUserMedia === 'function'
    );

    const prefersNativeCamera = () => typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;

    /** Webcam num modal. `single`: fecha na primeira captura (foto de perfil, logo). */
    const openCamera = ({ single = false, onCapture = null, afterClose = null } = {}) => {
        const elements = buildCameraModal();
        camera.open = true;
        camera.single = single;
        camera.shots = 0;
        camera.onCapture = onCapture;
        camera.afterClose = afterClose;
        elements.shots.replaceChildren();
        elements.title.innerHTML = single ? '<i class="bi bi-camera me-2"></i>Tirar foto' : '<i class="bi bi-camera me-2"></i>Tirar fotos';
        elements.done.textContent = single ? 'Cancelar' : 'Concluir';
        updateCameraCount();
        modal(elements.modal)?.show();
        startStream(readSavedDevice());
    };

    // ── seletores registrados (colar/arrastar valem para a página toda) ─────

    const registry = new Set();
    let lastActive = null;

    const topOpenModal = () => {
        const open = Array.from(document.querySelectorAll('.modal.show'));
        return open.length > 0 ? open[open.length - 1] : null;
    };

    /**
     * Para qual campo vai um Ctrl+V: com modal aberto, só o campo do modal;
     * senão o que tem o foco, o usado por último, o único marcado "página
     * inteira" ou o único visível. Ambíguo → ninguém (e o usuário é avisado).
     */
    const resolvePasteTarget = () => {
        const openModal = topOpenModal();
        if (openModal && openModal.id === 'erpImageCropModal') {
            return null;
        }
        if (openModal && openModal.id === 'erpImageCameraModal') {
            return camera.owner && registry.has(camera.owner) ? camera.owner : null;
        }
        if (document.querySelector('.swal2-popup.swal2-modal')) {
            return null;
        }

        // Campo que saiu do DOM (conteúdo de modal redesenhado) nunca recebe colar.
        const enabled = Array.from(registry).filter((picker) => picker.root.isConnected && !picker.busy());
        const candidates = enabled.filter((picker) => (openModal ? openModal.contains(picker.root) : picker.root.closest('.modal') === null));
        if (candidates.length === 0) {
            return null;
        }

        const active = document.activeElement;
        const focused = candidates.find((picker) => active instanceof Element && picker.root.contains(active));
        if (focused) {
            return focused;
        }
        if (lastActive && candidates.includes(lastActive) && isVisible(lastActive.root)) {
            return lastActive;
        }

        const pageScope = candidates.filter((picker) => picker.options.paste === 'page');
        if (pageScope.length === 1) {
            return pageScope[0];
        }

        const visible = candidates.filter((picker) => isVisible(picker.root));
        return visible.length === 1 ? visible[0] : null;
    };

    document.addEventListener('paste', (event) => {
        if (registry.size === 0 || event.defaultPrevented || isEditableTarget(event.target)) {
            return;
        }

        const files = filesFrom(event.clipboardData);
        if (files.length === 0) {
            return;
        }

        event.preventDefault();
        const target = resolvePasteTarget();
        if (!target) {
            toast('info', `Clique no campo de imagem onde quer colar e pressione ${PASTE_SHORTCUT} de novo.`);
            return;
        }

        target.deliver(files, 'clipboard').then((added) => {
            if (added > 0) {
                if (!isVisible(target.root)) {
                    target.options.reveal?.();
                }
                toast('info', `${plural(added, 'imagem colada', 'imagens coladas')}.`);
                target.bringIntoView();
            }
        });
    });

    // Durante qualquer arraste de arquivo os campos se destacam, e soltar fora
    // deles não abre a imagem no navegador (tiraria o usuário da tela).
    let pageDragDepth = 0;
    const setDragActive = (active) => registry.forEach((picker) => picker.root.classList.toggle('is-drag-active', active));

    window.addEventListener('dragenter', (event) => {
        if (registry.size === 0 || !hasDraggedFiles(event)) {
            return;
        }
        pageDragDepth += 1;
        setDragActive(true);
    });
    window.addEventListener('dragleave', (event) => {
        if (registry.size === 0 || !hasDraggedFiles(event)) {
            return;
        }
        pageDragDepth = Math.max(0, pageDragDepth - 1);
        if (pageDragDepth === 0) {
            setDragActive(false);
        }
    });
    window.addEventListener('dragover', (event) => {
        if (registry.size === 0 || event.defaultPrevented || !hasDraggedFiles(event)) {
            return;
        }
        const insidePicker = Array.from(registry).some((picker) => picker.root.contains(event.target));
        if (!insidePicker) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'none';
        }
    });
    window.addEventListener('drop', (event) => {
        pageDragDepth = 0;
        setDragActive(false);
        registry.forEach((picker) => picker.root.classList.remove('is-dragover'));
        if (registry.size > 0 && !event.defaultPrevented && hasDraggedFiles(event)) {
            event.preventDefault();
        }
    });
    window.addEventListener('dragend', () => {
        pageDragDepth = 0;
        setDragActive(false);
    });

    window.addEventListener('pagehide', stopStream);

    // Sair da tela com imagem ainda não enviada numa fila pergunta antes (specs/035).
    window.erpRegisterUnsavedWork?.(() => Array.from(registry).some((picker) => {
        try {
            return Boolean(picker.options.hasUnsavedWork?.());
        } catch (error) {
            return false;
        }
    }));

    // ── attach: as origens ligadas a um elemento ────────────────────────────

    const acceptAttribute = (types) => {
        const extras = [];
        if (types.includes('image/heic') || types.includes('image/heif')) extras.push('.heic', '.heif');
        if (types.includes('image/avif')) extras.push('.avif');
        if (types.includes('application/pdf')) extras.push('.pdf');

        return [...types, ...extras].join(',');
    };

    const ensureHiddenInput = (root, selector, configure) => {
        let input = root.querySelector(selector);
        if (!(input instanceof HTMLInputElement)) {
            input = document.createElement('input');
            input.type = 'file';
            input.className = 'd-none';
            input.tabIndex = -1;
            input.setAttribute('aria-hidden', 'true');
            configure(input);
            root.appendChild(input);
        }

        return input;
    };

    /**
     * Liga Câmera, Computador/galeria, Colar e arrastar a `root`. Cada lote já
     * preparado (formato conferido, nome normalizado, reduzido) chega em
     * `onFiles(files, source)`, que devolve quantos aceitou.
     */
    const attach = (root, rawOptions = {}) => {
        if (!(root instanceof HTMLElement)) {
            return null;
        }
        if (root.__erpImagePicker) {
            return root.__erpImagePicker;
        }

        const options = resolveOptions({ ...optionsFromDataset(root), ...rawOptions });
        const q = (selector) => root.querySelector(selector);
        const fileInput = ensureHiddenInput(root, '[data-image-picker-file]', (input) => {
            input.setAttribute('data-image-picker-file', '');
            input.accept = acceptAttribute(options.types);
            input.multiple = options.multiple;
        });
        const captureInput = ensureHiddenInput(root, '[data-image-picker-capture]', (input) => {
            input.setAttribute('data-image-picker-capture', '');
            input.accept = 'image/*';
            input.setAttribute('capture', 'environment');
        });

        const controller = {
            root,
            options,
            busy: () => {
                try {
                    return Boolean(options.isBusy?.());
                } catch (error) {
                    return false;
                }
            },
            async deliver(files, source = 'file') {
                const list = Array.from(files || []).filter((file) => file instanceof File);
                if (list.length === 0) {
                    return 0;
                }
                if (controller.busy()) {
                    toast('info', 'Aguarde o envio atual terminar para adicionar mais.');
                    return 0;
                }

                options.onPreparing?.(list.length);
                let prepared = { files: [], rejected: [] };
                try {
                    prepared = await prepare(list, options, source);
                } finally {
                    options.onPreparing?.(-list.length);
                }
                reportRejected(prepared.rejected);
                if (prepared.files.length === 0 || typeof options.onFiles !== 'function') {
                    return 0;
                }

                const added = await options.onFiles(prepared.files, source);

                return Number.isFinite(Number(added)) ? Number(added) : prepared.files.length;
            },
            openPicker() {
                if (!controller.busy()) {
                    fileInput.click();
                }
            },
            openCamera() {
                if (controller.busy()) {
                    return;
                }
                if (prefersNativeCamera() || !webcamAvailable()) {
                    if (!prefersNativeCamera()) {
                        toast('info', 'A câmera do navegador não está disponível aqui. Escolha a imagem no computador.');
                    }
                    captureInput.click();
                    return;
                }

                camera.owner = controller;
                openCamera({
                    single: !options.multiple,
                    onCapture: (file) => controller.deliver([file], 'camera'),
                    afterClose: (shots) => {
                        if (camera.owner === controller) {
                            camera.owner = null;
                        }
                        if (shots > 0) {
                            controller.bringIntoView();
                            options.afterCamera?.(shots);
                        }
                    },
                });
            },
            async pasteFromClipboard() {
                if (controller.busy()) {
                    return;
                }
                if (!navigator.clipboard || typeof navigator.clipboard.read !== 'function') {
                    toast('info', `Pressione ${PASTE_SHORTCUT} com esta tela aberta para colar a imagem.`);
                    return;
                }

                try {
                    const files = [];
                    for (const item of await navigator.clipboard.read()) {
                        const type = item.types.find((candidate) => options.types.includes(candidate))
                            || item.types.find((candidate) => candidate.startsWith('image/'));
                        if (!type) {
                            continue;
                        }
                        // eslint-disable-next-line no-await-in-loop -- poucos itens na área de transferência
                        const blob = await item.getType(type);
                        files.push(new File([blob], 'image', { type: blob.type || type, lastModified: Date.now() }));
                    }

                    if (files.length === 0) {
                        toast('info', 'Não há imagem na área de transferência. Copie uma imagem ou tire um print e tente de novo.');
                        return;
                    }
                    if (await controller.deliver(files, 'clipboard') > 0) {
                        controller.bringIntoView();
                    }
                } catch (error) {
                    // Permissão negada, ou só há arquivos copiados do Explorador —
                    // que a API assíncrona não entrega, mas o Ctrl+V entrega.
                    toast('info', `O navegador não liberou a área de transferência. Pressione ${PASTE_SHORTCUT} nesta tela.`);
                }
            },
            bringIntoView() {
                const target = options.viewTarget?.() || root;
                if (!(target instanceof HTMLElement) || !isVisible(target)) {
                    return;
                }
                const rect = target.getBoundingClientRect();
                if (rect.top < 0 || rect.bottom > window.innerHeight) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            },
            setBusy() {
                const busy = controller.busy();
                root.querySelectorAll('[data-image-picker-camera], [data-image-picker-pick], [data-image-picker-paste]').forEach((button) => {
                    if (button instanceof HTMLButtonElement) {
                        button.disabled = busy;
                    }
                });
                q('[data-image-picker-dropzone]')?.setAttribute('aria-disabled', busy ? 'true' : 'false');
            },
        };

        const deliverAndShow = (files, source) => controller.deliver(files, source).then((added) => {
            if (added > 0) {
                controller.bringIntoView();
            }
        });

        fileInput.addEventListener('change', () => {
            const files = Array.from(fileInput.files || []);
            fileInput.value = '';
            deliverAndShow(files, 'file');
        });
        captureInput.addEventListener('change', () => {
            const files = Array.from(captureInput.files || []);
            captureInput.value = '';
            deliverAndShow(files, 'camera');
        });

        root.querySelectorAll('[data-image-picker-pick]').forEach((button) => button.addEventListener('click', () => controller.openPicker()));
        root.querySelectorAll('[data-image-picker-camera]').forEach((button) => button.addEventListener('click', () => controller.openCamera()));
        root.querySelectorAll('[data-image-picker-paste]').forEach((button) => button.addEventListener('click', () => controller.pasteFromClipboard()));
        root.querySelectorAll('[data-image-picker-paste-key]').forEach((element) => {
            element.textContent = PASTE_SHORTCUT;
        });

        const dropzone = q('[data-image-picker-dropzone]');
        dropzone?.addEventListener('click', () => controller.openPicker());
        dropzone?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                controller.openPicker();
            }
        });

        let dragDepth = 0;
        root.addEventListener('dragenter', (event) => {
            if (!hasDraggedFiles(event)) {
                return;
            }
            event.preventDefault();
            dragDepth += 1;
            root.classList.add('is-dragover');
        });
        root.addEventListener('dragover', (event) => {
            if (!hasDraggedFiles(event)) {
                return;
            }
            event.preventDefault();
            event.dataTransfer.dropEffect = controller.busy() ? 'none' : 'copy';
        });
        root.addEventListener('dragleave', (event) => {
            if (!hasDraggedFiles(event)) {
                return;
            }
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) {
                root.classList.remove('is-dragover');
            }
        });
        root.addEventListener('drop', (event) => {
            if (!hasDraggedFiles(event)) {
                return;
            }
            event.preventDefault();
            dragDepth = 0;
            root.classList.remove('is-dragover');
            const files = filesFrom(event.dataTransfer);
            if (files.length === 0) {
                toast('warning', 'Nenhum arquivo encontrado no que foi solto.');
                return;
            }
            deliverAndShow(files, 'file');
        });

        const markActive = () => {
            lastActive = controller;
        };
        root.addEventListener('pointerdown', markActive);
        root.addEventListener('focusin', markActive);

        registry.add(controller);
        root.__erpImagePicker = controller;
        root.classList.add('image-picker-ready');

        return controller;
    };

    // ── cartão de imagem (fila ou campo) ────────────────────────────────────

    /**
     * Miniatura com origem, tamanho, Recortar (quando o navegador abre o
     * arquivo) e Remover. `item`: { id, file, url, source }.
     */
    const renderItem = (item, { onRemove = null, onCrop = null, disabled = false } = {}) => {
        const figure = document.createElement('figure');
        figure.className = 'image-picker-item';
        figure.dataset.imagePickerItem = String(item.id);

        const thumb = document.createElement('div');
        thumb.className = 'image-picker-thumb';
        const isPdf = typeOf(item.file) === 'application/pdf';
        const placeholder = (icon, text) => `<span class="image-picker-placeholder"><i class="bi ${icon}" aria-hidden="true"></i><small>${text}</small></span>`;

        let cropButton = null;
        if (isPdf) {
            thumb.innerHTML = placeholder('bi-file-earmark-pdf', 'PDF');
        } else {
            const image = document.createElement('img');
            image.alt = item.file.name;
            image.src = item.url;
            image.addEventListener('error', () => {
                // HEIC/HEIF (e AVIF em navegador antigo) não têm prévia nem recorte.
                thumb.innerHTML = placeholder('bi-file-earmark-image', 'Sem prévia');
                cropButton?.remove();
            }, { once: true });
            thumb.appendChild(image);
        }

        const caption = document.createElement('figcaption');
        const icon = document.createElement('i');
        icon.className = `bi ${SOURCE_ICONS[item.source] || SOURCE_ICONS.file}`;
        icon.setAttribute('aria-hidden', 'true');
        const size = document.createElement('span');
        size.textContent = formatBytes(item.file.size);
        caption.append(icon, size);
        caption.title = item.file.name;

        figure.append(thumb, caption);

        if (typeof onCrop === 'function' && canCrop(item.file)) {
            cropButton = document.createElement('button');
            cropButton.type = 'button';
            cropButton.className = 'image-picker-item-crop';
            cropButton.title = 'Recortar';
            cropButton.setAttribute('aria-label', `Recortar ${item.file.name}`);
            cropButton.innerHTML = '<i class="bi bi-crop" aria-hidden="true"></i>';
            cropButton.disabled = disabled;
            cropButton.addEventListener('click', () => onCrop(item));
            figure.appendChild(cropButton);
        }

        if (typeof onRemove === 'function') {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'image-picker-item-remove';
            remove.title = 'Remover';
            remove.setAttribute('aria-label', `Remover ${item.file.name}`);
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            remove.disabled = disabled;
            remove.addEventListener('click', () => onRemove(item));
            figure.appendChild(remove);
        }

        return figure;
    };

    const newItem = (file, source) => {
        itemSeq += 1;

        return { id: itemSeq, file, url: URL.createObjectURL(file), source };
    };

    // ── campo de formulário ─────────────────────────────────────────────────

    /**
     * Campo de formulário: as imagens escolhidas viram o conteúdo do
     * <input type="file" name=...> do form (DataTransfer), então a rota que
     * recebe o form não muda. Cada troca dispara `change` nesse input (quem
     * habilitava o botão de enviar por ele continua funcionando) e
     * `image-picker:change` na raiz.
     */
    const field = (root, rawOptions = {}) => {
        if (!(root instanceof HTMLElement)) {
            return null;
        }
        if (root.__erpImagePickerField) {
            return root.__erpImagePickerField;
        }

        const input = root.querySelector('[data-image-picker-input]');
        const list = root.querySelector('[data-image-picker-list]');
        const state = { items: [] };
        let controller = null;

        const sync = () => {
            if (input instanceof HTMLInputElement && typeof DataTransfer !== 'undefined') {
                const transfer = new DataTransfer();
                state.items.forEach((item) => transfer.items.add(item.file));
                input.files = transfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            root.classList.toggle('has-image-picker-items', state.items.length > 0);
            root.dispatchEvent(new CustomEvent('image-picker:change', {
                bubbles: true,
                detail: { files: state.items.map((item) => item.file), items: state.items.slice() },
            }));
        };

        const render = () => {
            if (list instanceof HTMLElement) {
                list.replaceChildren(...state.items.map((item) => renderItem(item, {
                    onRemove: (target) => {
                        URL.revokeObjectURL(target.url);
                        state.items = state.items.filter((candidate) => candidate !== target);
                        sync();
                        render();
                    },
                    onCrop: async (target) => {
                        const cropped = await crop(target.file, controller.options);
                        if (!cropped || !state.items.includes(target)) {
                            return;
                        }
                        URL.revokeObjectURL(target.url);
                        target.file = cropped;
                        target.url = URL.createObjectURL(cropped);
                        sync();
                        render();
                    },
                })));
            }
        };

        controller = attach(root, {
            ...rawOptions,
            onFiles: (files, source) => {
                const options = controller.options;
                if (!options.multiple) {
                    state.items.forEach((item) => URL.revokeObjectURL(item.url));
                    state.items = [newItem(files[0], source)];
                } else {
                    const room = options.maxFiles - state.items.length;
                    if (room <= 0) {
                        toast('warning', `Este campo aceita até ${plural(options.maxFiles, 'arquivo', 'arquivos')}.`);
                        return 0;
                    }
                    if (files.length > room) {
                        toast('warning', `Este campo aceita até ${plural(options.maxFiles, 'arquivo', 'arquivos')}; ${plural(files.length - room, 'ficou', 'ficaram')} de fora.`);
                    }
                    files.slice(0, room).forEach((file) => state.items.push(newItem(file, source)));
                }
                sync();
                render();

                return options.multiple ? Math.min(files.length, options.maxFiles) : 1;
            },
            viewTarget: () => list,
        });

        const clear = () => {
            state.items.forEach((item) => URL.revokeObjectURL(item.url));
            state.items = [];
            sync();
            render();
        };

        const form = root.closest('form');
        form?.addEventListener('reset', () => window.setTimeout(clear, 0));

        controller.clear = clear;
        controller.files = () => state.items.map((item) => item.file);
        root.__erpImagePickerField = controller;
        render();

        return controller;
    };

    // ── fila com Enviar (envio imediato) ────────────────────────────────────

    const errorMessageFor = (status, payload) => {
        const firstFieldError = payload && payload.errors && typeof payload.errors === 'object'
            ? Object.values(payload.errors).flat().find((message) => typeof message === 'string' && message !== '')
            : '';
        const message = firstFieldError || (payload && typeof payload.message === 'string' ? payload.message : '');

        if (status === 401) return 'Sua sessão expirou. Entre novamente para enviar.';
        if (status === 419) return 'A página ficou aberta tempo demais. Recarregue-a para enviar.';
        if (status === 413) return 'O envio passou do limite de tamanho. Tente com menos imagens por vez.';
        if (message !== '') return message;
        if (status === 429) return 'Muitos envios seguidos. Aguarde um minuto e tente de novo.';
        if (status === 0) return 'Sem conexão com o servidor. Verifique a rede e tente de novo.';
        // Redirecionamento seguido pelo XHR (login, falta de permissão) chega
        // como HTML com status 200.
        if (payload === null) return 'Sua sessão pode ter expirado. Recarregue a página e tente de novo.';

        return 'Não foi possível enviar. Tente novamente.';
    };

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const sendBatch = ({ url, fieldName, extras, batch, onProgress }) => new Promise((resolve, reject) => {
        const form = new FormData();
        form.append('_token', csrfToken());
        Object.entries(extras).forEach(([name, value]) => form.append(name, value));
        batch.forEach((item) => form.append(fieldName, item.file, item.file.name));

        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
        xhr.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable && event.total > 0) {
                onProgress(event.loaded / event.total);
            }
        });
        xhr.addEventListener('load', () => {
            let payload = null;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch (error) {
                payload = null;
            }
            if (xhr.status >= 200 && xhr.status < 300 && payload && payload.success === true) {
                resolve(payload);
                return;
            }
            reject(new Error(errorMessageFor(xhr.status, payload)));
        });
        xhr.addEventListener('error', () => reject(new Error(errorMessageFor(0, null))));
        xhr.addEventListener('abort', () => reject(new Error('O envio foi interrompido.')));
        xhr.send(form);
    });

    /**
     * Fila com "Enviar": nada é gravado antes do clique (em telas sem
     * exclusão, um Ctrl+V por engano não pode virar registro permanente). Envia
     * em lotes sequenciais de `data-image-picker-batch` (o limite por
     * requisição do backend); cada resposta JSON com `html` substitui o
     * conteúdo de `data-image-picker-gallery`. Campos `[data-image-picker-extra]`
     * com `name` vão junto em cada lote.
     */
    const uploader = (root, rawOptions = {}) => {
        if (!(root instanceof HTMLElement)) {
            return null;
        }
        if (root.__erpImagePickerUploader) {
            return root.__erpImagePickerUploader;
        }

        const data = root.dataset;
        const url = rawOptions.uploadUrl || data.imagePickerUploadUrl || '';
        if (url === '') {
            return null;
        }

        const fieldName = rawOptions.fieldName || data.imagePickerField || 'imagens[]';
        const batchSize = Math.max(1, Number(rawOptions.batchSize || data.imagePickerBatch || 4));
        const noun = data.imagePickerNoun || 'imagem';
        const nounPlural = data.imagePickerNounPlural || 'imagens';
        const verb = data.imagePickerVerb || 'adicionada';
        const verbPlural = data.imagePickerVerbPlural || 'adicionadas';
        const q = (selector) => root.querySelector(selector);
        const els = {
            gallery: rawOptions.gallery || (data.imagePickerGallery ? document.querySelector(data.imagePickerGallery) : null),
            dropzone: q('[data-image-picker-dropzone]'),
            dropzoneTitle: q('[data-image-picker-dropzone-title]'),
            queue: q('[data-image-picker-queue]'),
            queueTitle: q('[data-image-picker-queue-title]'),
            list: q('[data-image-picker-list]'),
            error: q('[data-image-picker-error]'),
            progress: q('[data-image-picker-progress]'),
            progressTrack: q('[data-image-picker-progress-track]'),
            progressBar: q('[data-image-picker-progress-bar]'),
            progressLabel: q('[data-image-picker-progress-label]'),
            clear: q('[data-image-picker-clear]'),
            submit: q('[data-image-picker-submit]'),
            submitLabel: q('[data-image-picker-submit-label]'),
            live: q('[data-image-picker-live]'),
        };
        const state = { items: [], preparing: 0, uploading: false };
        let controller = null;

        const announce = (message) => {
            if (els.live instanceof HTMLElement) {
                els.live.textContent = '';
                window.setTimeout(() => { els.live.textContent = message; }, 50);
            }
        };

        const extras = () => {
            const values = {};
            root.querySelectorAll('[data-image-picker-extra][name]').forEach((element) => {
                values[element.name] = element.value;
            });

            return values;
        };

        const extraLabel = () => {
            const select = root.querySelector('select[data-image-picker-extra]');

            return select instanceof HTMLSelectElement && select.selectedIndex >= 0
                ? (select.options[select.selectedIndex]?.textContent || '').trim()
                : '';
        };

        const hideError = () => {
            els.error?.classList.add('d-none');
            if (els.error) {
                els.error.textContent = '';
            }
        };

        const render = () => {
            const count = state.items.length;
            const busy = state.uploading || state.preparing > 0;
            root.classList.toggle('has-image-picker-queue', count > 0 || state.preparing > 0);
            els.queue?.classList.toggle('d-none', count === 0 && state.preparing === 0);

            if (els.queueTitle) {
                els.queueTitle.textContent = state.preparing > 0
                    ? `Preparando ${plural(state.preparing, noun, nounPlural)}…`
                    : `${plural(count, `${noun} pronta`, `${nounPlural} prontas`)} para enviar`;
            }
            els.list?.replaceChildren(...state.items.map((item) => renderItem(item, {
                disabled: state.uploading,
                onRemove: (target) => {
                    if (state.uploading) return;
                    URL.revokeObjectURL(target.url);
                    state.items = state.items.filter((candidate) => candidate !== target);
                    render();
                },
                onCrop: async (target) => {
                    if (state.uploading) return;
                    const cropped = await crop(target.file, controller.options);
                    if (!cropped || !state.items.includes(target)) return;
                    URL.revokeObjectURL(target.url);
                    target.file = cropped;
                    target.url = URL.createObjectURL(cropped);
                    render();
                },
            })));

            if (els.submitLabel) {
                els.submitLabel.textContent = state.uploading ? 'Enviando…' : `Enviar ${plural(count, noun, nounPlural)}`;
            }
            if (els.submit instanceof HTMLButtonElement) {
                els.submit.disabled = busy || count === 0;
            }
            if (els.clear instanceof HTMLButtonElement) {
                els.clear.disabled = state.uploading || count === 0;
            }
            root.querySelectorAll('[data-image-picker-extra]').forEach((element) => {
                element.disabled = state.uploading;
            });
            controller?.setBusy();
        };

        const setProgress = (fraction, label) => {
            const percent = Math.max(0, Math.min(100, Math.round(fraction * 100)));
            els.progress?.classList.remove('d-none');
            if (els.progressBar instanceof HTMLElement) {
                els.progressBar.style.width = `${percent}%`;
            }
            els.progressTrack?.setAttribute('aria-valuenow', String(percent));
            if (els.progressLabel) {
                els.progressLabel.textContent = label;
            }
        };

        const replaceGallery = (html) => {
            if (!(els.gallery instanceof HTMLElement) || typeof html !== 'string') {
                return;
            }
            els.gallery.innerHTML = html;
            window.DesktopUi?.refreshPhotoViewers?.(els.gallery);
            els.dropzone?.classList.remove('is-empty');
            if (els.dropzoneTitle && els.dropzoneTitle.dataset.imagePickerMoreTitle) {
                els.dropzoneTitle.textContent = els.dropzoneTitle.dataset.imagePickerMoreTitle;
            }
        };

        const upload = async () => {
            if (state.uploading || state.preparing > 0 || state.items.length === 0) {
                return;
            }

            const fields = extras();
            const label = extraLabel();
            const total = state.items.length;
            const totalBytes = state.items.reduce((sum, item) => sum + item.file.size, 0) || 1;
            let sentBytes = 0;
            let sentCount = 0;

            state.uploading = true;
            hideError();
            render();

            try {
                while (state.items.length > 0) {
                    const batch = state.items.slice(0, batchSize);
                    const batchBytes = batch.reduce((sum, item) => sum + item.file.size, 0);
                    const range = batch.length === 1 ? `${sentCount + 1} de ${total}` : `${sentCount + 1}–${sentCount + batch.length} de ${total}`;

                    setProgress(sentBytes / totalBytes, `Enviando ${range}…`);
                    // eslint-disable-next-line no-await-in-loop -- lotes em sequência (limite do backend)
                    const payload = await sendBatch({
                        url,
                        fieldName,
                        extras: fields,
                        batch,
                        onProgress: (fraction) => setProgress(
                            (sentBytes + (batchBytes * fraction)) / totalBytes,
                            // Upload a 100% ainda espera o servidor processar.
                            fraction >= 1 ? `Processando ${range} no servidor…` : `Enviando ${range}…`
                        ),
                    });

                    batch.forEach((item) => URL.revokeObjectURL(item.url));
                    state.items = state.items.filter((item) => !batch.includes(item));
                    sentBytes += batchBytes;
                    sentCount += batch.length;
                    replaceGallery(payload.html);
                    root.dispatchEvent(new CustomEvent('image-picker:uploaded', { bubbles: true, detail: payload }));
                    render();
                }

                const message = `${plural(sentCount, `${noun} ${verb}`, `${nounPlural} ${verbPlural}`)}${label !== '' ? ` em ${label}` : ''}.`;
                toast('success', message);
                announce(message);
            } catch (error) {
                const reason = error instanceof Error ? error.message : String(error);
                const message = sentCount > 0
                    ? `${sentCount} de ${total} foram enviadas. As que ficaram na fila não: ${reason}`
                    : reason;
                if (els.error) {
                    els.error.textContent = message;
                    els.error.classList.remove('d-none');
                }
                toast('error', sentCount > 0 ? 'Parte não foi enviada.' : 'Nada foi enviado.');
                announce(message);
            } finally {
                state.uploading = false;
                els.progress?.classList.add('d-none');
                render();
            }
        };

        const clear = async () => {
            if (state.uploading || state.items.length === 0) {
                return;
            }
            if (state.items.length > 1) {
                const question = `Descartar ${plural(state.items.length, noun, nounPlural)} da fila?`;
                let confirmed = false;
                if (typeof Swal !== 'undefined') {
                    const answer = await Swal.fire({
                        icon: 'question',
                        title: question,
                        text: 'Ainda não foram enviadas.',
                        showCancelButton: true,
                        confirmButtonText: 'Descartar',
                        cancelButtonText: 'Manter na fila',
                        focusCancel: true,
                    });
                    confirmed = answer.isConfirmed;
                } else {
                    confirmed = window.confirm(question);
                }
                if (!confirmed) {
                    return;
                }
            }
            state.items.forEach((item) => URL.revokeObjectURL(item.url));
            state.items = [];
            hideError();
            render();
        };

        controller = attach(root, {
            ...rawOptions,
            paste: rawOptions.paste || data.imagePickerPaste || 'page',
            isBusy: () => state.uploading,
            hasUnsavedWork: () => state.items.length > 0 || state.preparing > 0 || state.uploading,
            onPreparing: (delta) => {
                state.preparing = Math.max(0, state.preparing + delta);
                render();
            },
            viewTarget: () => els.queue,
            afterCamera: () => {
                if (els.submit instanceof HTMLButtonElement && !els.submit.disabled) {
                    els.submit.focus({ preventScroll: true });
                }
            },
            onFiles: (files, source) => {
                const room = controller.options.maxFiles - state.items.length;
                if (room <= 0) {
                    toast('warning', `A fila aceita até ${controller.options.maxFiles}. Envie as que estão nela antes de adicionar mais.`);
                    return 0;
                }
                if (files.length > room) {
                    toast('warning', `A fila aceita até ${controller.options.maxFiles}; ${plural(files.length - room, 'ficou', 'ficaram')} de fora.`);
                }
                const added = files.slice(0, room);
                added.forEach((file) => state.items.push(newItem(file, source)));
                hideError();
                render();
                announce(`${plural(added.length, `${noun} ${verb}`, `${nounPlural} ${verbPlural}`)} à fila.`);

                return added.length;
            },
        });

        els.submit?.addEventListener('click', upload);
        els.clear?.addEventListener('click', clear);

        controller.upload = upload;
        controller.items = () => state.items.slice();
        root.__erpImagePickerUploader = controller;
        render();

        return controller;
    };

    const init = (scope = document) => {
        scope.querySelectorAll('[data-image-picker="field"]').forEach((root) => field(root));
        scope.querySelectorAll('[data-image-picker="uploader"]').forEach((root) => uploader(root));
    };

    window.ErpImagePicker = {
        attach,
        field,
        uploader,
        crop,
        canCrop,
        prepare,
        renderItem,
        openCamera,
        init,
        typeOf,
        formatBytes,
        PASTE_SHORTCUT,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init());
    } else {
        init();
    }
})();
