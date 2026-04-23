import { useEffect, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import Navbar from '../../components/Navbar';
import api from '../../api';
import { ReservationRequest } from '@samchlolaurepartners/shared';

interface ReservationDetail extends ReservationRequest {
  reservation_id?: number;
  confirmed_at?: string;
  cancelled_at?: string;
  notes?: string;
}

export default function PartnerReservationDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [reservation, setReservation] = useState<ReservationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [notes, setNotes] = useState('');
  const [processing, setProcessing] = useState(false);

  const load = () => {
    api.get<{ data: ReservationDetail }>(`/api/reservations/${id}`)
      .then((res) => {
        setReservation(res.data.data);
        setNotes(res.data.data.notes ?? '');
      })
      .catch(console.error)
      .finally(() => setLoading(false));
  };

  useEffect(load, [id]);

  const handleConfirm = async () => {
    setProcessing(true);
    try {
      await api.put(`/api/reservations/${id}/confirm`, { notes });
      load();
    } catch {
      alert('Erreur lors de la confirmation');
    } finally {
      setProcessing(false);
    }
  };

  const handleCancel = async () => {
    if (!confirm('Annuler cette réservation ?')) return;
    setProcessing(true);
    try {
      await api.put(`/api/reservations/${id}/cancel`);
      load();
    } catch {
      alert('Erreur lors de l\'annulation');
    } finally {
      setProcessing(false);
    }
  };

  if (loading) return <div className="min-h-screen flex items-center justify-center"><div className="animate-spin h-8 w-8 border-b-2 border-brand-500 rounded-full" /></div>;
  if (!reservation) return <div className="text-center py-12 text-gray-400">Introuvable</div>;

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-3xl mx-auto px-4 py-8">
        <button onClick={() => navigate(-1)} className="text-brand-500 text-sm mb-4">← Retour</button>

        <div className="flex items-center justify-between mb-6">
          <h1 className="text-2xl font-bold text-gray-900">Demande #{reservation.id}</h1>
          <span className={`badge-${reservation.status}`}>
            {reservation.status === 'pending' ? 'En attente' : reservation.status === 'confirmed' ? 'Confirmée' : 'Annulée'}
          </span>
        </div>

        <div className="card p-6 space-y-4 mb-6">
          <h2 className="font-semibold text-gray-900">Informations client</h2>
          <div className="grid grid-cols-2 gap-4 text-sm">
            <div><span className="text-gray-400">Nom :</span> <span className="font-medium">{reservation.client_name}</span></div>
            <div><span className="text-gray-400">Email :</span> <a href={`mailto:${reservation.client_email}`} className="text-brand-500">{reservation.client_email}</a></div>
            {reservation.client_phone && <div><span className="text-gray-400">Tél :</span> {reservation.client_phone}</div>}
          </div>
        </div>

        <div className="card p-6 space-y-4 mb-6">
          <h2 className="font-semibold text-gray-900">Détails du séjour</h2>
          <div className="grid grid-cols-2 gap-4 text-sm">
            <div><span className="text-gray-400">Hébergement :</span> <span className="font-medium">{reservation.property_name || '—'}</span></div>
            <div><span className="text-gray-400">Arrivée :</span> <span className="font-medium">{reservation.checkin_date}</span></div>
            <div><span className="text-gray-400">Départ :</span> <span className="font-medium">{reservation.checkout_date}</span></div>
            <div><span className="text-gray-400">Voyageurs :</span> {reservation.adults} adulte(s), {reservation.children} enfant(s)</div>
          </div>
          {reservation.message && (
            <div className="text-sm">
              <span className="text-gray-400">Message :</span>
              <p className="mt-1 text-gray-700 bg-gray-50 rounded p-3">{reservation.message}</p>
            </div>
          )}
        </div>

        {reservation.status === 'pending' && (
          <div className="card p-6 space-y-4">
            <h2 className="font-semibold text-gray-900">Action</h2>
            <p className="text-sm text-gray-500">
              Veuillez d'abord réserver manuellement sur mauritius-booking.com, puis confirmer ici pour notifier le client.
            </p>
            <div>
              <label className="label">Notes internes (optionnel)</label>
              <textarea className="input" rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>
            <div className="flex gap-3">
              <button onClick={handleConfirm} disabled={processing} className="btn-primary">
                ✓ Confirmer la réservation
              </button>
              <button onClick={handleCancel} disabled={processing} className="btn-secondary text-red-600 border-red-200">
                ✕ Annuler
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
