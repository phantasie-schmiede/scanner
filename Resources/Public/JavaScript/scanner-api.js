(function (global) {
    'use strict';

    const DEFAULT_FORMATS = ['qr_code', 'ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'codabar'];

    class ScannerClient {
        constructor(rootElement, options = {}) {
            if (!rootElement) {
                throw new Error('Scanner root element is required.');
            }

            this.root = rootElement;
            this.options = {
                apiBase: options.apiBase || this.root.dataset.apiBase || '/scanner/api/v1',
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
        }

        async init() {
            this.bindUi();
            await this.emitCapabilities();
            this.emit('ready', { apiBase: this.options.apiBase, formats: this.options.formats });
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
                this.emitPermission('unavailable', 'Camera access requires HTTPS or localhost.');
                return;
            }

            if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
                this.emitError('camera_not_supported', 'Camera API is not supported by this browser.');
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
                    this.emitError('video_target_missing', 'No video element configured for live scanning.');
                    return;
                }

                this.videoElement.srcObject = this.stream;
                await this.videoElement.play();
                this.isRunning = true;
                this.emitPermission('granted', 'Camera access granted.');
                this.setStatus('Live-Scan aktiv.');

                this.detector = await this.createDetector();
                this.scanCameraFrame();
            } catch (error) {
                this.emitPermission('denied', 'Camera access denied.');
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

            this.setStatus('Live-Scan gestoppt.');
            this.emit('stopped', {});
        }

        async scanCameraFrame() {
            if (!this.isRunning || !this.videoElement) {
                return;
            }

            try {
                const result = await this.detectFromSource(this.videoElement, 'camera');
                if (result) {
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

                this.emitError('no_code_found', 'Kein Barcode oder QR-Code gefunden.');
            } catch (error) {
                this.emitError('image_scan_failed', error);
            }
        }

        async createDetector() {
            if ('BarcodeDetector' in window) {
                try {
                    return new window.BarcodeDetector({ formats: this.options.formats });
                } catch (error) {
                    this.emitError('barcode_detector_init_failed', error);
                }
            }

            return null;
        }

        async detectFromSource(source, sourceType) {
            if (this.detector) {
                const detections = await this.detector.detect(source);
                if (detections && detections[0] && detections[0].rawValue) {
                    return {
                        content: detections[0].rawValue,
                        format: (detections[0].format || '').toLowerCase(),
                        source: sourceType,
                        clientTimestamp: new Date().toISOString(),
                        meta: {
                            mode: this.detector ? 'barcode_detector' : 'unknown',
                        },
                    };
                }
            }

            if (global.Quagga && typeof global.Quagga.decodeSingle === 'function') {
                return await this.detectFromImageWithQuagga(source);
            }

            return null;
        }

        async detectFromImageWithQuagga(image) {
            const canvas = document.createElement('canvas');
            canvas.width = image.naturalWidth || image.videoWidth || image.width;
            canvas.height = image.naturalHeight || image.videoHeight || image.height;
            const context = canvas.getContext('2d');
            context.drawImage(image, 0, 0);

            const dataUrl = canvas.toDataURL('image/png');

            return await new Promise((resolve) => {
                global.Quagga.decodeSingle(
                    {
                        src: dataUrl,
                        numOfWorkers: 0,
                        decoder: {
                            readers: [
                                'ean_reader',
                                'ean_8_reader',
                                'upc_reader',
                                'upc_e_reader',
                                'code_128_reader',
                                'code_39_reader',
                                'i2of5_reader',
                                'codabar_reader',
                            ],
                        },
                    },
                    (result) => {
                        if (result && result.codeResult && result.codeResult.code) {
                            resolve({
                                content: result.codeResult.code,
                                format: this.mapQuaggaFormat(result.codeResult.format),
                                source: 'image',
                                clientTimestamp: new Date().toISOString(),
                                meta: {
                                    mode: 'quagga2',
                                },
                            });
                            return;
                        }

                        resolve(null);
                    }
                );
            });
        }

        mapQuaggaFormat(quaggaFormat) {
            const formatMap = {
                ean_13: 'ean_13',
                ean_8: 'ean_8',
                upc_a: 'upc_a',
                upc_e: 'upc_e',
                code_128: 'code_128',
                code_39: 'code_39',
                i2of5: 'itf',
                codabar: 'codabar',
            };

            return formatMap[(quaggaFormat || '').toLowerCase()] || 'code_128';
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
                img.onload = () => resolve(img);
                img.onerror = () => reject(new Error('Image loading failed.'));
                img.src = objectUrl;
                img.addEventListener(
                    'load',
                    () => {
                        URL.revokeObjectURL(objectUrl);
                    },
                    { once: true }
                );
            });
        }

        renderResult(result) {
            if (this.resultElement) {
                this.resultElement.textContent = `${result.content} (${result.format})`;
            }
            this.setStatus('Scan erfolgreich.');
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
            this.setStatus(`Fehler: ${message}`);
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
