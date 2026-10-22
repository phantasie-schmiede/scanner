(function (global) {
    'use strict';

    const DEFAULT_FORMATS = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar'];

    // Maps the scanner format names to the canonical zxing-wasm format names.
    const ZXING_FORMATS = {
        ean_13: 'EAN13',
        ean_8: 'EAN8',
        upc_a: 'UPCA',
        upc_e: 'UPCE',
        code_128: 'Code128',
        code_39: 'Code39',
        itf: 'ITF',
        codabar: 'Codabar',
    };

    class ScannerClient {
        constructor(rootElement, options = {}) {
            if (!rootElement) {
                throw new Error('Scanner root element is required.');
            }

            this.root = rootElement;
            this.options = {
                apiBase: options.apiBase || this.root.dataset.apiBase || '/scanner/api/v1',
                zxingWasm: options.zxingWasm || this.root.dataset.zxingWasm || '',
                formats: Array.isArray(options.formats) && options.formats.length > 0 ? options.formats : DEFAULT_FORMATS,
                autoSubmitResult: options.autoSubmitResult !== false,
                onScan: options.onScan || null,
                onError: options.onError || null,
                onPermission: options.onPermission || null,
            };

            this.videoElement = this.root.querySelector('[data-scanner-video]');
            this.fileInput = this.root.querySelector('[data-scanner-file]');
            this.startButton = this.root.querySelector('[data-scanner-start]');
            this.stopButton = this.root.querySelector('[data-scanner-stop]');
            this.statusElement = this.root.querySelector('[data-scanner-status]');
            this.resultElement = this.root.querySelector('[data-scanner-result]');

            this.stream = null;
            this.isRunning = false;
            this.animationFrameId = null;
            this.detector = null;
            this.detectorInitialised = false;
            this.i18n = {};
        }

        async init() {
            this.loadI18n();
            this.bindUi();
            this.configureZxingWasm();
            await this.emitCapabilities();
            this.emit('ready', { apiBase: this.options.apiBase, formats: this.options.formats });
        }

        loadI18n() {
            const element = this.root.querySelector('[data-psbits-scanner-i18n]');
            if (!element) {
                return;
            }

            try {
                this.i18n = JSON.parse(element.textContent);
            } catch (error) {
                this.i18n = {};
            }
        }

        t(key, ...args) {
            let text = this.i18n[key] || key;

            args.forEach((arg, index) => {
                text = text.replace(`{${index}}`, String(arg));
            });

            return text;
        }

        configureZxingWasm() {
            if (
                typeof global.ZXingWASM === 'object' &&
                typeof global.ZXingWASM.setZXingModuleOverrides === 'function' &&
                this.options.zxingWasm
            ) {
                global.ZXingWASM.setZXingModuleOverrides({
                    locateFile: () => this.options.zxingWasm,
                });
            }
        }

        bindUi() {
            if (this.startButton) {
                this.startButton.addEventListener('click', async () => {
                    await this.startCamera();
                });
            }

            if (this.stopButton) {
                this.stopButton.addEventListener('click', () => {
                    this.stopCamera();
                });
            }

            if (this.fileInput) {
                this.fileInput.addEventListener('change', async (event) => {
                    const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
                    if (!file) {
                        return;
                    }
                    await this.scanImageFile(file);
                });
            }
        }

        async emitCapabilities() {
            try {
                const response = await fetch(`${this.options.apiBase}/capabilities`, { method: 'GET', credentials: 'same-origin' });
                const payload = await response.json();
                this.emit('capabilities', payload);
            } catch (error) {
                this.emitError('capabilities_request_failed', error);
            }
        }

        async startCamera() {
            if (!window.isSecureContext) {
                this.emitPermission('unavailable', this.t('cameraRequiresHttps'));
                return;
            }

            if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
                this.emitError('camera_not_supported', this.t('cameraNotSupported'));
                return;
            }

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                    },
                    audio: false,
                });

                if (!this.videoElement) {
                    this.emitError('video_target_missing', this.t('videoTargetMissing'));
                    return;
                }

                this.videoElement.srcObject = this.stream;
                await this.videoElement.play();
                this.isRunning = true;
                this.emitPermission('granted', this.t('cameraGranted'));
                this.setStatus(this.t('liveScanActive'));

                this.getDetector();
                this.scanCameraFrame();
            } catch (error) {
                this.emitPermission('denied', this.t('cameraDenied'));
                this.emitError('camera_access_failed', error);
            }
        }

        stopCamera() {
            this.isRunning = false;
            if (this.animationFrameId) {
                cancelAnimationFrame(this.animationFrameId);
                this.animationFrameId = null;
            }

            if (this.stream) {
                this.stream.getTracks().forEach((track) => track.stop());
                this.stream = null;
            }

            if (this.videoElement) {
                this.videoElement.pause();
                this.videoElement.srcObject = null;
            }

            this.setStatus(this.t('liveScanStopped'));
            this.emit('stopped', {});
        }

        async scanCameraFrame() {
            if (!this.isRunning || !this.videoElement) {
                return;
            }

            try {
                const result = await this.detectFromSource(this.videoElement, 'camera');
                if (result) {
                    this.stopCamera();
                    await this.handleScanResult(result);
                }
            } catch (error) {
                this.emitError('live_scan_failed', error);
            }

            if (this.isRunning) {
                this.animationFrameId = requestAnimationFrame(() => this.scanCameraFrame());
            }
        }

        async scanImageFile(file) {
            try {
                const image = await this.loadImageFromFile(file);
                const result = await this.detectFromSource(image, 'image');
                if (result) {
                    await this.handleScanResult(result);
                    return;
                }

                this.emitError('no_code_found', this.t('noCodeFound'));
            } catch (error) {
                this.emitError('image_scan_failed', error);
            }
        }

        getDetector() {
            if (this.detectorInitialised) {
                return this.detector;
            }

            this.detectorInitialised = true;

            if ('BarcodeDetector' in window) {
                try {
                    this.detector = new window.BarcodeDetector({ formats: this.options.formats });
                } catch (error) {
                    this.emitError('barcode_detector_init_failed', error);
                }
            }

            return this.detector;
        }

        async detectFromSource(source, sourceType) {
            const detector = this.getDetector();
            if (detector) {
                const detections = await detector.detect(source);
                if (detections && detections[0] && detections[0].rawValue) {
                    return {
                        content: detections[0].rawValue,
                        format: (detections[0].format || '').toLowerCase(),
                        source: sourceType,
                        clientTimestamp: new Date().toISOString(),
                        meta: {
                            mode: 'barcode_detector',
                        },
                    };
                }
            }

            return await this.detectFromSourceWithZxing(source, sourceType);
        }

        async detectFromSourceWithZxing(source, sourceType) {
            if (typeof global.ZXingWASM !== 'object' || typeof global.ZXingWASM.readBarcodes !== 'function') {
                return null;
            }

            try {
                const image = await this.toImageData(source);
                const formats = this.options.formats.map((format) => ZXING_FORMATS[format]).filter(Boolean);
                const results = await global.ZXingWASM.readBarcodes(image, { formats });
                const result = results.find((item) => item.isValid && item.text) || null;
                if (!result) {
                    return null;
                }

                return {
                    content: result.text,
                    format: this.mapZxingFormat(result.format),
                    source: sourceType,
                    clientTimestamp: new Date().toISOString(),
                    meta: {
                        mode: 'zxing_wasm',
                    },
                };
            } catch (error) {
                this.emitError('zxing_wasm_failed', error);

                return null;
            }
        }

        async toImageData(source) {
            const width = source.naturalWidth || source.videoWidth || source.width;
            const height = source.naturalHeight || source.videoHeight || source.height;
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext('2d');
            context.drawImage(source, 0, 0);

            return context.getImageData(0, 0, width, height);
        }

        mapZxingFormat(zxingFormat) {
            const entry = Object.entries(ZXING_FORMATS).find(([, zxingName]) => zxingName === zxingFormat);

            return entry ? entry[0] : 'unknown';
        }

        async handleScanResult(result) {
            this.renderResult(result);
            this.emit('scan', result);
            if (typeof this.options.onScan === 'function') {
                this.options.onScan(result);
            }

            if (this.options.autoSubmitResult) {
                await this.submitResult(result);
            }
        }

        async submitResult(result) {
            try {
                const response = await fetch(`${this.options.apiBase}/result`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(result),
                });

                const payload = await response.json();
                if (!payload.success) {
                    this.emitError('result_rejected', payload.error || 'Result was rejected by API.');
                    return;
                }

                this.emit('submitted', payload.data);
            } catch (error) {
                this.emitError('result_submit_failed', error);
            }
        }

        async loadImageFromFile(file) {
            return await new Promise((resolve, reject) => {
                const img = new Image();
                const objectUrl = URL.createObjectURL(file);
                img.onload = () => {
                    URL.revokeObjectURL(objectUrl);
                    resolve(img);
                };
                img.onerror = () => {
                    URL.revokeObjectURL(objectUrl);
                    reject(new Error('Image loading failed.'));
                };
                img.src = objectUrl;
            });
        }

        renderResult(result) {
            if (this.resultElement) {
                this.resultElement.textContent = `${result.content} (${result.format})`;
            }
            this.setStatus(this.t('scanSuccessful'));
        }

        setStatus(message) {
            if (this.statusElement) {
                this.statusElement.textContent = message;
            }
        }

        emitPermission(status, message) {
            const payload = { status, message };
            this.emit('permission', payload);
            if (typeof this.options.onPermission === 'function') {
                this.options.onPermission(payload);
            }
        }

        emitError(code, error) {
            const message = error instanceof Error ? error.message : String(error || 'Unknown error');
            const payload = { code, message };
            this.setStatus(this.t('error', message));
            this.emit('error', payload);
            if (typeof this.options.onError === 'function') {
                this.options.onError(payload);
            }
        }

        emit(name, detail) {
            this.root.dispatchEvent(new CustomEvent(`psbits-scanner:${name}`, { detail }));
        }
    }

    function bootstrapAutoInit() {
        const elements = document.querySelectorAll('[data-psbits-scanner]');
        elements.forEach((element) => {
            const client = new ScannerClient(element);
            client.init().catch((error) => {
                element.dispatchEvent(
                    new CustomEvent('psbits-scanner:error', {
                        detail: {
                            code: 'init_failed',
                            message: error instanceof Error ? error.message : String(error),
                        },
                    })
                );
            });
            element.psbitsScanner = client;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrapAutoInit);
    } else {
        bootstrapAutoInit();
    }

    global.PSBitsScanner = {
        ScannerClient,
    };
})(window);
