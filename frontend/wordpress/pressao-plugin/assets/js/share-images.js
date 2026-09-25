/**
 * Download / Web Share de imagens para postar (Media Library).
 * Exposto em window.PressaoShareImages para widget.js e fluxo.js.
 */
(function (global) {
    'use strict';

    var REVOKE_DELAY_MS = 2000;
    var DOWNLOAD_STAGGER_MS = 250;
    var blobCache = Object.create(null);

    function isMobile() {
        return /Android|iPhone|iPad|iPod|Mobile|IEMobile|BlackBerry/i.test(
            global.navigator && global.navigator.userAgent ? global.navigator.userAgent : ''
        );
    }

    function filenameFor(img, index) {
        if (img && img.filename) {
            return img.filename;
        }
        return 'imagem-campanha' + (typeof index === 'number' ? '-' + index : '');
    }

    function cacheKey(img) {
        return (img && img.url) || '';
    }

    function fetchBlob(img) {
        var key = cacheKey(img);
        if (!key) {
            return Promise.reject(new Error('missing url'));
        }
        if (blobCache[key]) {
            return blobCache[key];
        }
        var pending = fetch(key, { mode: 'cors' }).then(function (response) {
            if (!response.ok) {
                throw new Error('download failed');
            }
            return response.blob();
        });
        blobCache[key] = pending.catch(function (err) {
            delete blobCache[key];
            throw err;
        });
        return blobCache[key];
    }

    function blobToFile(blob, filename) {
        var type = blob.type || 'image/jpeg';
        try {
            return new File([blob], filename, { type: type });
        } catch (e) {
            // Safari antigo: File pode falhar; Blob com name ajuda em alguns canShare
            blob.name = filename;
            return blob;
        }
    }

    function canShareFiles(files) {
        if (!global.navigator || typeof global.navigator.share !== 'function') {
            return false;
        }
        if (typeof global.navigator.canShare !== 'function') {
            return false;
        }
        try {
            return global.navigator.canShare({ files: files });
        } catch (e) {
            return false;
        }
    }

    function preferNativeShare(files) {
        return isMobile() && canShareFiles(files);
    }

    function triggerBlobDownload(blob, filename) {
        var objectUrl = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = objectUrl;
        a.download = filename;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
        global.setTimeout(function () {
            URL.revokeObjectURL(objectUrl);
        }, REVOKE_DELAY_MS);
    }

    function openUrlFallback(url) {
        var a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    function shareFiles(files) {
        return global.navigator.share({ files: files }).then(function () {
            return { aborted: false };
        }).catch(function (err) {
            if (err && err.name === 'AbortError') {
                return { aborted: true };
            }
            if (err && err.name === 'NotAllowedError') {
                return { aborted: true };
            }
            throw err;
        });
    }

    function prefetch(imagens) {
        if (!Array.isArray(imagens)) {
            return;
        }
        imagens.forEach(function (img) {
            if (img && img.url) {
                fetchBlob(img).catch(function () {});
            }
        });
    }

    function downloadOrShareOne(img, index) {
        if (!img || !img.url) {
            return Promise.resolve();
        }
        var filename = filenameFor(img, index);
        return fetchBlob(img)
            .then(function (blob) {
                var file = blobToFile(blob, filename);
                var files = [file];
                if (preferNativeShare(files)) {
                    return shareFiles(files);
                }
                triggerBlobDownload(blob, filename);
            })
            .catch(function () {
                openUrlFallback(img.url);
            });
    }

    function downloadOrShareAll(imagens) {
        var lista = Array.isArray(imagens) ? imagens.filter(function (img) {
            return img && img.url;
        }) : [];
        if (!lista.length) {
            return Promise.resolve();
        }

        return Promise.all(
            lista.map(function (img) {
                return fetchBlob(img).then(function (blob) {
                    return { img: img, blob: blob };
                });
            })
        )
            .then(function (ready) {
                var files = ready.map(function (item, i) {
                    return blobToFile(item.blob, filenameFor(item.img, i));
                });

                if (preferNativeShare(files)) {
                    return shareFiles(files);
                }

                // Mobile com canShare só para 1 arquivo: share sequencial (para se o usuário cancelar)
                if (isMobile() && files.length > 1 && canShareFiles([files[0]])) {
                    var chain = Promise.resolve({ aborted: false });
                    files.forEach(function (file) {
                        chain = chain.then(function (prev) {
                            if (prev && prev.aborted) {
                                return prev;
                            }
                            return shareFiles([file]);
                        });
                    });
                    return chain;
                }

                ready.forEach(function (item, i) {
                    global.setTimeout(function () {
                        triggerBlobDownload(item.blob, filenameFor(item.img, i));
                    }, i * DOWNLOAD_STAGGER_MS);
                });
            })
            .catch(function () {
                // Se o lote falhou (CORS etc.), tenta um a um com fallback
                var chain = Promise.resolve();
                lista.forEach(function (img, i) {
                    chain = chain.then(function () {
                        return new Promise(function (resolve) {
                            global.setTimeout(function () {
                                downloadOrShareOne(img, i).then(resolve, resolve);
                            }, i === 0 ? 0 : DOWNLOAD_STAGGER_MS);
                        });
                    });
                });
                return chain;
            });
    }

    global.PressaoShareImages = {
        isMobile: isMobile,
        prefetch: prefetch,
        downloadOrShareOne: downloadOrShareOne,
        downloadOrShareAll: downloadOrShareAll,
    };
})(typeof window !== 'undefined' ? window : this);
