import axios from 'axios';
import { LodgifyProperty, LodgifyAvailabilityDay, LodgifyRate } from '@samchlolaurepartners/shared';
import { getCache, setCache } from '../middleware/lodgifyCache';

const BASE_URL = process.env.LODGIFY_BASE_URL ?? 'https://api.lodgify.com/v2';
const API_KEY = process.env.LODGIFY_API_KEY ?? '';
const CACHE_TTL = 3600; // 1 hour

const client = axios.create({
  baseURL: BASE_URL,
  headers: {
    'X-ApiKey': API_KEY,
    'Accept': 'application/json',
  },
});

export async function getProperties(): Promise<LodgifyProperty[]> {
  const cacheKey = 'lodgify:properties';
  const cached = getCache<LodgifyProperty[]>(cacheKey);
  if (cached) return cached;

  const { data } = await client.get('/properties');
  const properties: LodgifyProperty[] = (data.items ?? data ?? []).map(mapProperty);

  setCache(cacheKey, properties, CACHE_TTL);
  return properties;
}

export async function getProperty(propertyId: number): Promise<LodgifyProperty> {
  const cacheKey = `lodgify:property:${propertyId}`;
  const cached = getCache<LodgifyProperty>(cacheKey);
  if (cached) return cached;

  const { data } = await client.get(`/properties/${propertyId}`);
  const property = mapProperty(data);

  setCache(cacheKey, property, CACHE_TTL);
  return property;
}

export async function getAvailability(
  propertyId: number,
  from: string,
  to: string
): Promise<LodgifyAvailabilityDay[]> {
  const cacheKey = `lodgify:availability:${propertyId}:${from}:${to}`;
  const cached = getCache<LodgifyAvailabilityDay[]>(cacheKey);
  if (cached) return cached;

  const { data } = await client.get(`/availability/${propertyId}`, {
    params: { startDate: from, endDate: to },
  });

  const days: LodgifyAvailabilityDay[] = (data ?? []).map((d: Record<string, unknown>) => ({
    date: String(d.date),
    available: Boolean(d.available),
    min_stay: Number(d.min_nights ?? 1),
  }));

  setCache(cacheKey, days, 1800); // 30 min TTL for availability
  return days;
}

export async function getRates(
  propertyId: number,
  from: string,
  to: string,
  guests: number
): Promise<LodgifyRate[]> {
  const cacheKey = `lodgify:rates:${propertyId}:${from}:${to}:${guests}`;
  const cached = getCache<LodgifyRate[]>(cacheKey);
  if (cached) return cached;

  const { data } = await client.get(`/rates/${propertyId}`, {
    params: { startDate: from, endDate: to, numberOfGuests: guests },
  });

  const rates: LodgifyRate[] = (data?.periods ?? data ?? []).map((r: Record<string, unknown>) => ({
    date_from: String(r.start_date ?? r.startDate ?? from),
    date_to: String(r.end_date ?? r.endDate ?? to),
    price_per_night: Number(r.price ?? r.pricePerNight ?? 0),
    currency: String(r.currency ?? 'EUR'),
  }));

  setCache(cacheKey, rates, 1800);
  return rates;
}

// ─── Internal mapping helpers ─────────────────────────────────────────────────

function mapProperty(d: Record<string, unknown>): LodgifyProperty {
  return {
    id: Number(d.id),
    name: String(d.name ?? ''),
    description: String(d.description ?? ''),
    images: ((d.images as Record<string, unknown>[] | undefined) ?? []).map((img) => ({
      url: String(img.url ?? img.src ?? ''),
      text: img.text != null ? String(img.text) : null,
    })),
    amenities: ((d.amenities as Record<string, unknown>[] | undefined) ?? []).map((a) => ({
      name: String(a.name ?? a),
    })),
    latitude: d.latitude != null ? Number(d.latitude) : null,
    longitude: d.longitude != null ? Number(d.longitude) : null,
    max_guests: Number(d.max_guests ?? d.maxGuests ?? 0),
    bedrooms: Number(d.bedrooms ?? 0),
    bathrooms: Number(d.bathrooms ?? 0),
  };
}
