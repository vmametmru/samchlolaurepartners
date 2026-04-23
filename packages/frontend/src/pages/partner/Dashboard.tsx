import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import Navbar from '../../components/Navbar';
import api from '../../api';
import { ReservationRequest } from '@samchlolaurepartners/shared';

export default function PartnerDashboard() {
  const [requests, setRequests] = useState<ReservationRequest[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.get<{ data: ReservationRequest[] }>('/api/reservations')
      .then((res) => setRequests(res.data.data))
      .catch(console.error)
      .finally(() => setLoading(false));
  }, []);

  const pending = requests.filter((r) => r.status === 'pending').length;
  const confirmed = requests.filter((r) => r.status === 'confirmed').length;

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-6xl mx-auto px-4 py-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Dashboard</h1>

        {/* Stats */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
          <div className="card p-5">
            <p className="text-sm text-gray-500">Total demandes</p>
            <p className="text-3xl font-bold text-gray-900">{requests.length}</p>
          </div>
          <div className="card p-5">
            <p className="text-sm text-gray-500">En attente</p>
            <p className="text-3xl font-bold text-yellow-600">{pending}</p>
          </div>
          <div className="card p-5">
            <p className="text-sm text-gray-500">Confirmées</p>
            <p className="text-3xl font-bold text-green-600">{confirmed}</p>
          </div>
        </div>

        {/* Quick nav */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
          {[
            { to: '/partner/reservations', label: 'Réservations', icon: '📋' },
            { to: '/partner/templates', label: 'Templates email', icon: '✉️' },
            { to: '/partner/settings', label: 'Paramètres', icon: '⚙️' },
            { to: '/properties', label: 'Hébergements', icon: '🏠' },
          ].map((item) => (
            <Link key={item.to} to={item.to} className="card p-4 text-center hover:shadow-md transition-shadow">
              <div className="text-2xl mb-1">{item.icon}</div>
              <div className="text-sm font-medium text-gray-700">{item.label}</div>
            </Link>
          ))}
        </div>

        {/* Recent requests */}
        <div className="card">
          <div className="px-5 py-4 border-b border-gray-100">
            <h2 className="font-semibold text-gray-900">Demandes récentes</h2>
          </div>
          {loading ? (
            <div className="p-8 text-center">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-brand-500 mx-auto" />
            </div>
          ) : requests.length === 0 ? (
            <div className="p-8 text-center text-gray-400">Aucune demande</div>
          ) : (
            <div className="divide-y divide-gray-50">
              {requests.slice(0, 5).map((r) => (
                <Link key={r.id} to={`/partner/reservations/${r.id}`} className="flex items-center px-5 py-3 hover:bg-gray-50">
                  <div className="flex-1">
                    <p className="font-medium text-gray-900">{r.client_name}</p>
                    <p className="text-sm text-gray-500">{r.property_name} · {r.checkin_date} → {r.checkout_date}</p>
                  </div>
                  <span className={`badge-${r.status}`}>
                    {r.status === 'pending' ? 'En attente' : r.status === 'confirmed' ? 'Confirmée' : 'Annulée'}
                  </span>
                </Link>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
