import { useEffect, useRef } from 'react';
import { LodgifyProperty } from '@samchlolaurepartners/shared';

interface Props {
  properties: LodgifyProperty[];
  onMarkerClick?: (property: LodgifyProperty) => void;
}

export default function MapView({ properties, onMarkerClick }: Props) {
  const mapRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<import('leaflet').Map | null>(null);

  useEffect(() => {
    if (!mapRef.current || mapInstanceRef.current) return;

    // Dynamically import leaflet to avoid SSR issues
    import('leaflet').then((L) => {
      if (!mapRef.current) return;

      const map = L.map(mapRef.current, {
        center: [-20.2, 57.5], // Mauritius
        zoom: 10,
      });

      L.tileLayer(
        import.meta.env.VITE_MAP_TILE_URL ?? 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
          attribution: '© OpenStreetMap contributors',
          maxZoom: 18,
        }
      ).addTo(map);

      mapInstanceRef.current = map;
    });

    return () => {
      mapInstanceRef.current?.remove();
      mapInstanceRef.current = null;
    };
  }, []);

  // Update markers when properties change
  useEffect(() => {
    const map = mapInstanceRef.current;
    if (!map) return;

    import('leaflet').then((L) => {
      // Remove all existing markers
      map.eachLayer((layer) => {
        if ((layer as import('leaflet').Marker).getLatLng) {
          map.removeLayer(layer);
        }
      });

      properties.forEach((prop) => {
        if (prop.latitude == null || prop.longitude == null) return;

        const marker = L.marker([prop.latitude, prop.longitude]);
        marker.bindPopup(`
          <div class="text-sm font-semibold">${prop.name}</div>
          <div class="text-xs text-gray-500">${prop.bedrooms} chambre(s) · ${prop.max_guests} pers.</div>
        `);

        if (onMarkerClick) {
          marker.on('click', () => onMarkerClick(prop));
        }

        marker.addTo(map);
      });
    });
  }, [properties, onMarkerClick]);

  return <div ref={mapRef} className="w-full h-full rounded-xl" style={{ minHeight: '400px' }} />;
}
