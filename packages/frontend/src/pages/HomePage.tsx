import { useState, useCallback } from 'react';
import Navbar from '../components/Navbar';
import SearchBar, { SearchParams } from '../components/SearchBar';
import PropertyCard from '../components/PropertyCard';
import MapView from '../components/MapView';
import { LodgifyProperty } from '@samchlolaurepartners/shared';
import api from '../api';
import { useTenant } from '../context/TenantContext';

export default function HomePage() {
  const { partner } = useTenant();
  const [properties, setProperties] = useState<LodgifyProperty[]>([]);
  const [searched, setSearched] = useState(false);
  const [loading, setLoading] = useState(false);

  const handleSearch = useCallback(async (params: SearchParams) => {
    setLoading(true);
    setSearched(false);
    try {
      const res = await api.get<{ data: LodgifyProperty[] }>('/api/lodgify/properties', {
        params: {
          from: params.checkin,
          to: params.checkout,
          guests: params.adults + params.children,
        },
      });
      setProperties(res.data.data);
    } catch {
      setProperties([]);
    } finally {
      setLoading(false);
      setSearched(true);
    }
  }, []);

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />

      {/* Hero */}
      <div
        className="relative bg-gradient-to-r from-brand-500 to-teal-500 text-white py-16 px-4"
        style={partner?.primary_color ? { background: `linear-gradient(to right, ${partner.primary_color}, #14b8a6)` } : {}}
      >
        <div className="max-w-4xl mx-auto text-center">
          <h1 className="text-4xl font-bold mb-3">
            {partner ? `Bienvenue chez ${partner.name}` : 'Trouvez votre hébergement idéal'}
          </h1>
          <p className="text-lg opacity-90 mb-8">
            Séjours exceptionnels à l'île Maurice
          </p>

          {/* Search bar */}
          <SearchBar onSearch={handleSearch} />
        </div>
      </div>

      {/* Results */}
      <div className="max-w-7xl mx-auto px-4 py-10">
        {loading && (
          <div className="text-center py-12">
            <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-brand-500 mx-auto" />
          </div>
        )}

        {searched && !loading && (
          <div className="flex flex-col lg:flex-row gap-6">
            {/* Property grid */}
            <div className="flex-1">
              <h2 className="text-xl font-semibold text-gray-900 mb-4">
                {properties.length} hébergement{properties.length !== 1 ? 's' : ''} disponible{properties.length !== 1 ? 's' : ''}
              </h2>
              {properties.length === 0 ? (
                <div className="text-center py-12 text-gray-500">
                  Aucun hébergement disponible pour ces dates.
                </div>
              ) : (
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                  {properties.map((p) => (
                    <PropertyCard key={p.id} property={p} />
                  ))}
                </div>
              )}
            </div>

            {/* Map */}
            {properties.length > 0 && (
              <div className="lg:w-96 lg:sticky lg:top-20 lg:self-start h-96 lg:h-[70vh]">
                <MapView properties={properties} />
              </div>
            )}
          </div>
        )}

        {!searched && !loading && (
          <div className="text-center py-12 text-gray-400">
            Utilisez la recherche ci-dessus pour trouver des hébergements disponibles
          </div>
        )}
      </div>
    </div>
  );
}
