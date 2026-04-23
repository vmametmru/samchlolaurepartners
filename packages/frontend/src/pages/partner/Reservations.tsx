import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import Navbar from '../../components/Navbar';
import api from '../../api';
import { ReservationRequest } from '@samchlolaurepartners/shared';

export default function PartnerReservations() {
  const [reservations, setReservations] = useState<ReservationRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<'all' | 'pending' | 'confirmed' | 'cancelled'>('all');

  useEffect(() => {
    api.get<{ data: ReservationRequest[] }>('/api/reservations')
      .then((res) => setReservations(res.data.data))
      .catch(console.error)
      .finally(() => setLoading(false));
  }, []);

  const filtered = filter === 'all' ? reservations : reservations.filter((r) => r.status === filter);

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-5xl mx-auto px-4 py-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Demandes de réservation</h1>

        {/* Filter tabs */}
        <div className="flex gap-2 mb-6">
          {(['all', 'pending', 'confirmed', 'cancelled'] as const).map((f) => (
            <button
              key={f}
              onClick={() => setFilter(f)}
              className={`px-4 py-1.5 rounded-full text-sm font-medium transition-colors ${
                filter === f ? 'bg-brand-500 text-white' : 'bg-white text-gray-600 border border-gray-200'
              }`}
            >
              {f === 'all' ? 'Toutes' : f === 'pending' ? 'En attente' : f === 'confirmed' ? 'Confirmées' : 'Annulées'}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="text-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-500 mx-auto" />
          </div>
        ) : (
          <div className="card overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-100">
                <tr>
                  <th className="text-left px-4 py-3 font-medium text-gray-600">Client</th>
                  <th className="text-left px-4 py-3 font-medium text-gray-600">Hébergement</th>
                  <th className="text-left px-4 py-3 font-medium text-gray-600">Dates</th>
                  <th className="text-left px-4 py-3 font-medium text-gray-600">Voyageurs</th>
                  <th className="text-left px-4 py-3 font-medium text-gray-600">Statut</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {filtered.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="text-center py-8 text-gray-400">Aucune demande</td>
                  </tr>
                ) : (
                  filtered.map((r) => (
                    <tr key={r.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <Link to={`/partner/reservations/${r.id}`} className="font-medium text-brand-500 hover:underline">
                          {r.client_name}
                        </Link>
                        <p className="text-gray-400 text-xs">{r.client_email}</p>
                      </td>
                      <td className="px-4 py-3 text-gray-700">{r.property_name || '—'}</td>
                      <td className="px-4 py-3 text-gray-600 whitespace-nowrap">
                        {r.checkin_date} → {r.checkout_date}
                      </td>
                      <td className="px-4 py-3 text-gray-600">{r.adults}A · {r.children}E</td>
                      <td className="px-4 py-3">
                        <span className={`badge-${r.status}`}>
                          {r.status === 'pending' ? 'En attente' : r.status === 'confirmed' ? 'Confirmée' : 'Annulée'}
                        </span>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
