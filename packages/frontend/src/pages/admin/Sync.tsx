import { useState } from 'react';
import Navbar from '../../components/Navbar';
import api from '../../api';

export default function AdminSync() {
  const [syncing, setSyncing] = useState(false);
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const handleSync = async () => {
    setSyncing(true);
    setResult(null);
    setError(null);
    try {
      const res = await api.post<{ message: string }>('/api/lodgify/sync');
      setResult(res.data.message ?? 'Synchronisation terminée');
    } catch {
      setError('Erreur lors de la synchronisation');
    } finally {
      setSyncing(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-2xl mx-auto px-4 py-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-2">Synchronisation Lodgify</h1>
        <p className="text-gray-500 mb-8">
          Force la mise à jour du cache des propriétés depuis l'API Lodgify.
          Le cache est normalement rafraîchi automatiquement toutes les heures.
        </p>

        <div className="card p-8 text-center space-y-4">
          <div className="text-5xl">🔄</div>
          <p className="text-gray-600">
            Cette action efface le cache local et recharge toutes les propriétés depuis Lodgify.
          </p>

          <button
            onClick={handleSync}
            disabled={syncing}
            className="btn-primary"
          >
            {syncing ? 'Synchronisation...' : 'Synchroniser maintenant'}
          </button>

          {result && (
            <div className="bg-green-50 border border-green-200 text-green-700 rounded-lg px-4 py-3 text-sm">
              ✓ {result}
            </div>
          )}

          {error && (
            <div className="bg-red-50 border border-red-200 text-red-700 rounded-lg px-4 py-3 text-sm">
              ✕ {error}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
