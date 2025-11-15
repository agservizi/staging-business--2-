(function (global) {
    'use strict';

    function escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDateTime(value) {
        if (!value) {
            return '';
        }
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }
        return date.toLocaleString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function formatBytes(bytes) {
        const value = Number(bytes) || 0;
        if (value <= 0) {
            return '0 B';
        }
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const exponent = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
        const size = value / Math.pow(1024, exponent);
        return `${size.toFixed(size >= 10 ? 0 : 1)} ${units[exponent]}`;
    }

    function coerceBoolean(value) {
        return value === true || value === 'true' || value === 1 || value === '1';
    }

    const helpers = Object.freeze({
        escapeHtml,
        formatDateTime,
        formatBytes,
        coerceBoolean
    });

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = helpers;
    }

    global.CAFPatronatoHelpers = helpers;
}(typeof window !== 'undefined' ? window : globalThis));
