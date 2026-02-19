(function (global) {
  'use strict';

  const DEFAULT_OPTIONS = {
    enableHighAccuracy: true,
    timeout: 15000,
    maximumAge: 0,
  };

  const GEO_ERROR = {
    PERMISSION_DENIED: 1,
    POSITION_UNAVAILABLE: 2,
    TIMEOUT: 3,
    NOT_SECURE_CONTEXT: 'NOT_SECURE_CONTEXT',
    NOT_SUPPORTED: 'NOT_SUPPORTED',
    UNKNOWN: 'UNKNOWN',
  };

  function isLocalhost(hostname) {
    return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]';
  }

  function isInAppWebView(ua) {
    const agent = ua || '';
    return /(FBAN|FBAV|Instagram|Line\/(?!.*Chrome)|; wv\)|WebView|WhatsApp)/i.test(agent);
  }

  function getCurrentPosition(options) {
    return new Promise((resolve, reject) => {
      global.navigator.geolocation.getCurrentPosition(resolve, reject, options);
    });
  }

  function mapError(error) {
    const code = typeof error === 'object' && error && typeof error.code !== 'undefined'
      ? Number(error.code)
      : GEO_ERROR.UNKNOWN;

    if (code === GEO_ERROR.PERMISSION_DENIED) {
      return {
        code,
        key: 'permission_denied',
        message: 'Izin lokasi ditolak. Aktifkan izin lokasi di Site Settings Chrome/Safari dan pastikan Location perangkat aktif.',
      };
    }

    if (code === GEO_ERROR.POSITION_UNAVAILABLE) {
      return {
        code,
        key: 'position_unavailable',
        message: 'Lokasi tidak tersedia. Cek GPS/sinyal lalu coba lagi.',
      };
    }

    if (code === GEO_ERROR.TIMEOUT) {
      return {
        code,
        key: 'timeout',
        message: 'Timeout mengambil lokasi. Pindah ke area lebih terbuka, aktifkan High Accuracy, lalu coba lagi.',
      };
    }

    if (code === GEO_ERROR.NOT_SECURE_CONTEXT) {
      return {
        code,
        key: 'not_secure_context',
        message: 'Geolocation membutuhkan HTTPS. Buka halaman ini melalui https://.',
      };
    }

    if (code === GEO_ERROR.NOT_SUPPORTED) {
      return {
        code,
        key: 'not_supported',
        message: 'Browser ini tidak mendukung geolocation.',
      };
    }

    return {
      code: GEO_ERROR.UNKNOWN,
      key: 'unknown',
      message: 'Gagal mengambil lokasi. Silakan coba lagi.',
    };
  }

  async function capture(options) {
    const finalOptions = Object.assign({}, DEFAULT_OPTIONS, options || {});

    if (!global.navigator || !global.navigator.geolocation) {
      throw mapError({ code: GEO_ERROR.NOT_SUPPORTED });
    }

    if (!global.isSecureContext && !isLocalhost(global.location.hostname)) {
      throw mapError({ code: GEO_ERROR.NOT_SECURE_CONTEXT });
    }

    try {
      const pos = await getCurrentPosition(finalOptions);
      return {
        lat: Number(pos.coords.latitude),
        lng: Number(pos.coords.longitude),
        accuracy: Number(pos.coords.accuracy || 0),
        ts: Number(pos.timestamp || Date.now()),
      };
    } catch (error) {
      if (Number(error && error.code) === GEO_ERROR.TIMEOUT) {
        const retryOptions = Object.assign({}, finalOptions, {
          enableHighAccuracy: true,
          timeout: 25000,
        });

        try {
          const posRetry = await getCurrentPosition(retryOptions);
          return {
            lat: Number(posRetry.coords.latitude),
            lng: Number(posRetry.coords.longitude),
            accuracy: Number(posRetry.coords.accuracy || 0),
            ts: Number(posRetry.timestamp || Date.now()),
          };
        } catch (retryError) {
          throw mapError(retryError);
        }
      }

      throw mapError(error);
    }
  }

  global.Geo = {
    capture,
    mapError,
    isInAppWebView,
    constants: GEO_ERROR,
  };
})(window);
