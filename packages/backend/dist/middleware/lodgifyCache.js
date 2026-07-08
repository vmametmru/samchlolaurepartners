"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getCache = getCache;
exports.setCache = setCache;
exports.invalidateCache = invalidateCache;
const cache = new Map();
function getCache(key) {
    const entry = cache.get(key);
    if (!entry)
        return null;
    if (Date.now() > entry.expiresAt) {
        cache.delete(key);
        return null;
    }
    return entry.data;
}
function setCache(key, data, ttlSeconds = 3600) {
    cache.set(key, { data, expiresAt: Date.now() + ttlSeconds * 1000 });
}
function invalidateCache(prefix) {
    for (const key of cache.keys()) {
        if (key.startsWith(prefix)) {
            cache.delete(key);
        }
    }
}
//# sourceMappingURL=lodgifyCache.js.map