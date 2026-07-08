"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.getProperties = getProperties;
exports.getProperty = getProperty;
exports.getAvailability = getAvailability;
exports.getRates = getRates;
const axios_1 = __importDefault(require("axios"));
const lodgifyCache_1 = require("../middleware/lodgifyCache");
const BASE_URL = process.env.LODGIFY_BASE_URL ?? 'https://api.lodgify.com/v2';
const API_KEY = process.env.LODGIFY_API_KEY ?? '';
const CACHE_TTL = 3600; // 1 hour
const client = axios_1.default.create({
    baseURL: BASE_URL,
    headers: {
        'X-ApiKey': API_KEY,
        'Accept': 'application/json',
    },
});
async function getProperties() {
    const cacheKey = 'lodgify:properties';
    const cached = (0, lodgifyCache_1.getCache)(cacheKey);
    if (cached)
        return cached;
    const { data } = await client.get('/properties');
    const properties = (data.items ?? data ?? []).map(mapProperty);
    (0, lodgifyCache_1.setCache)(cacheKey, properties, CACHE_TTL);
    return properties;
}
async function getProperty(propertyId) {
    const cacheKey = `lodgify:property:${propertyId}`;
    const cached = (0, lodgifyCache_1.getCache)(cacheKey);
    if (cached)
        return cached;
    const { data } = await client.get(`/properties/${propertyId}`);
    const property = mapProperty(data);
    (0, lodgifyCache_1.setCache)(cacheKey, property, CACHE_TTL);
    return property;
}
async function getAvailability(propertyId, from, to) {
    const cacheKey = `lodgify:availability:${propertyId}:${from}:${to}`;
    const cached = (0, lodgifyCache_1.getCache)(cacheKey);
    if (cached)
        return cached;
    const { data } = await client.get(`/availability/${propertyId}`, {
        params: { startDate: from, endDate: to },
    });
    const days = (data ?? []).map((d) => ({
        date: String(d.date),
        available: Boolean(d.available),
        min_stay: Number(d.min_nights ?? 1),
    }));
    (0, lodgifyCache_1.setCache)(cacheKey, days, 1800); // 30 min TTL for availability
    return days;
}
async function getRates(propertyId, from, to, guests) {
    const cacheKey = `lodgify:rates:${propertyId}:${from}:${to}:${guests}`;
    const cached = (0, lodgifyCache_1.getCache)(cacheKey);
    if (cached)
        return cached;
    const { data } = await client.get(`/rates/${propertyId}`, {
        params: { startDate: from, endDate: to, numberOfGuests: guests },
    });
    const rates = (data?.periods ?? data ?? []).map((r) => ({
        date_from: String(r.start_date ?? r.startDate ?? from),
        date_to: String(r.end_date ?? r.endDate ?? to),
        price_per_night: Number(r.price ?? r.pricePerNight ?? 0),
        currency: String(r.currency ?? 'EUR'),
    }));
    (0, lodgifyCache_1.setCache)(cacheKey, rates, 1800);
    return rates;
}
// ─── Internal mapping helpers ─────────────────────────────────────────────────
function mapProperty(d) {
    return {
        id: Number(d.id),
        name: String(d.name ?? ''),
        description: String(d.description ?? ''),
        images: (d.images ?? []).map((img) => ({
            url: String(img.url ?? img.src ?? ''),
            text: img.text != null ? String(img.text) : null,
        })),
        amenities: (d.amenities ?? []).map((a) => ({
            name: String(a.name ?? a),
        })),
        latitude: d.latitude != null ? Number(d.latitude) : null,
        longitude: d.longitude != null ? Number(d.longitude) : null,
        max_guests: Number(d.max_guests ?? d.maxGuests ?? 0),
        bedrooms: Number(d.bedrooms ?? 0),
        bathrooms: Number(d.bathrooms ?? 0),
    };
}
//# sourceMappingURL=lodgifyService.js.map