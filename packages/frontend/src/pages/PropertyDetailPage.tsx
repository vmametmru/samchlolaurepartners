import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import Navbar from '../components/Navbar';
import PhotoGallery from '../components/PhotoGallery';
import AvailabilityCalendar from '../components/AvailabilityCalendar';
import BookingRequestForm from '../components/BookingRequestForm';
import { LodgifyProperty, LodgifyAvailabilityDay, RateWithMarkup } from '@samchlolaurepartners/shared';
import api from '../api';

export default function PropertyDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [property, setProperty] = useState<LodgifyProperty | null>(null);
  const [availability, setAvailability] = useState<LodgifyAvailabilityDay[]>([]);
  const [rates, setRates] = useState<RateWithMarkup[]>([]);
  const [loading, setLoading] = useState(true);
  const [showBooking, setShowBooking] = useState(false);

  const today = new Date().toISOString().split('T')[0];
  const nextMonth = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

  useEffect(() => {
    if (!id) return;

    Promise.all([
      api.get<{ data: LodgifyProperty }>(`/api/lodgify/properties/${id}`),
      api.get<{ data: LodgifyAvailabilityDay[] }>(`/api/lodgify/properties/${id}/availability`, {
        params: { from: today, to: nextMonth },
      }),
      api.get<{ data: RateWithMarkup[] }>(`/api/lodgify/properties/${id}/rates`, {
        params: { from: today, to: nextMonth, guests: 2 },
      }),
    ])
      .then(([propRes, availRes, ratesRes]) => {
        setProperty(propRes.data.data);
        setAvailability(availRes.data.data);
        setRates(ratesRes.data.data);
      })
      .catch(console.error)
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-brand-500" />
      </div>
    );
  }

  if (!property) {
    return (
      <div className="min-h-screen flex items-center justify-center text-gray-400">
        Hébergement introuvable
      </div>
    );
  }

  const minRate = rates.length > 0 ? Math.min(...rates.map((r) => r.price_per_night)) : null;
  const currency = rates[0]?.currency ?? 'EUR';

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />

      <div className="max-w-5xl mx-auto px-4 py-8">
        <h1 className="text-3xl font-bold text-gray-900 mb-2">{property.name}</h1>
        <div className="flex items-center gap-4 text-sm text-gray-500 mb-6">
          <span>{property.bedrooms} chambre(s)</span>
          <span>·</span>
          <span>{property.bathrooms} salle(s) de bain</span>
          <span>·</span>
          <span>{property.max_guests} personnes max</span>
          {minRate && (
            <>
              <span>·</span>
              <span className="font-semibold text-gray-900">
                À partir de {minRate.toLocaleString('fr-FR', { style: 'currency', currency })}/nuit
              </span>
            </>
          )}
        </div>

        {/* Photo gallery */}
        <div className="mb-8">
          <PhotoGallery images={property.images} />
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
          {/* Left: Description + Amenities + Calendar */}
          <div className="lg:col-span-2 space-y-6">
            <div>
              <h2 className="text-xl font-semibold text-gray-900 mb-3">Description</h2>
              <p className="text-gray-600 leading-relaxed whitespace-pre-line">{property.description}</p>
            </div>

            {property.amenities.length > 0 && (
              <div>
                <h2 className="text-xl font-semibold text-gray-900 mb-3">Équipements</h2>
                <div className="grid grid-cols-2 gap-2">
                  {property.amenities.map((a, i) => (
                    <div key={i} className="flex items-center gap-2 text-sm text-gray-600">
                      <span className="text-green-500">✓</span>
                      {a.name}
                    </div>
                  ))}
                </div>
              </div>
            )}

            <div>
              <h2 className="text-xl font-semibold text-gray-900 mb-3">Disponibilités</h2>
              <div className="bg-white rounded-xl p-4 border border-gray-100">
                <AvailabilityCalendar availability={availability} />
              </div>
            </div>
          </div>

          {/* Right: Booking form */}
          <div className="lg:col-span-1">
            <div className="card p-5 sticky top-20">
              {!showBooking ? (
                <div className="text-center space-y-3">
                  {minRate && (
                    <p className="text-2xl font-bold text-gray-900">
                      {minRate.toLocaleString('fr-FR', { style: 'currency', currency })}
                      <span className="text-sm font-normal text-gray-400">/nuit</span>
                    </p>
                  )}
                  <button
                    onClick={() => setShowBooking(true)}
                    className="btn-primary w-full"
                  >
                    Faire une demande de réservation
                  </button>
                  <p className="text-xs text-gray-400">Aucun paiement requis à ce stade</p>
                </div>
              ) : (
                <BookingRequestForm
                  propertyId={id}
                  propertyName={property.name}
                  onSuccess={() => setShowBooking(false)}
                />
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
