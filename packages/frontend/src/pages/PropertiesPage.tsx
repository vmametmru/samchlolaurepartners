import { useEffect, useState } from 'react';
import Navbar from '../components/Navbar';
import PropertyCard from '../components/PropertyCard';
import MapView from '../components/MapView';
import { LodgifyProperty } from '@samchlolaurepartners/shared';
import api from '../api';

export default function PropertiesPage() {
  const [properties, setProperties] = useState<LodgifyProperty[]>([]);
  const [loading, setLoading] = useState(true);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [selected, setSelected] = useState<LodgifyProperty | null>(null);
  const [search, setSearch] = useState('');

  useEffect(() => {
    api.get<{ data: LodgifyProperty[] }>('/api/lodgify/properties')
      .then((res) => setProperties(res.data.data))
      .catch((err) => {
        console.error(err);
        setFetchError('Impossible de charger les hébergements. Vérifiez que le serveur est accessible.');
      })
      .finally(() => setLoading(false));
  }, []);

  const filtered = properties.filter((p) =>
    p.name.toLowerCase().includes(search.toLowerCase()) ||
    p.description.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />

      <div className="max-w-7xl mx-auto px-4 py-8">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
          <h1 className="text-2xl font-bold text-gray-900">Tous les hébergements</h1>
          <input
            type="text"
            className="input max-w-xs"
            placeholder="Rechercher..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>

        {loading ? (
          <div className="text-center py-16">
            <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-brand-500 mx-auto" />
          </div>
        ) : fetchError ? (
          <div className="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-6 text-center">
            {fetchError}
          </div>
        ) : (
          <div className="flex flex-col lg:flex-row gap-6">
            {/* List */}
            <div className="flex-1">
              <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                {filtered.map((p) => (
                  <div
                    key={p.id}
                    onClick={() => setSelected(p)}
                    className={`cursor-pointer transition-all ${selected?.id === p.id ? 'ring-2 ring-brand-500 rounded-2xl' : ''}`}
                  >
                    <PropertyCard property={p} />
                  </div>
                ))}
              </div>
              {filtered.length === 0 && (
                <div className="text-center py-12 text-gray-400">Aucun résultat</div>
              )}
            </div>

            {/* Map */}
            <div className="lg:w-96 lg:sticky lg:top-20 lg:self-start h-96 lg:h-[70vh]">
              <MapView
                properties={filtered}
                onMarkerClick={(p) => setSelected(p)}
              />
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
