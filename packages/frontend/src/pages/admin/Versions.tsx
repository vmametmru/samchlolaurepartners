import { useEffect, useState } from 'react';
import Navbar from '../../components/Navbar';
import api from '../../api';
import { AppVersion, DbMigration } from '@samchlolaurepartners/shared';

export default function AdminVersions() {
  const [versions, setVersions] = useState<AppVersion[]>([]);
  const [migrations, setMigrations] = useState<DbMigration[]>([]);
  const [newVersion, setNewVersion] = useState('');
  const [notes, setNotes] = useState('');
  const [deploying, setDeploying] = useState(false);

  const load = () => {
    api.get<{ data: AppVersion[] }>('/api/versions').then((r) => setVersions(r.data.data)).catch(console.error);
    api.get<{ data: DbMigration[] }>('/api/versions/migrations').then((r) => setMigrations(r.data.data)).catch(console.error);
  };

  useEffect(load, []);

  const handleDeploy = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newVersion.trim()) return;
    setDeploying(true);
    try {
      await api.post('/api/versions/deploy', { version: newVersion, notes });
      setNewVersion('');
      setNotes('');
      load();
    } catch {
      alert('Erreur lors du déploiement');
    } finally {
      setDeploying(false);
    }
  };

  const handleRollback = async (id: number, version: string) => {
    if (!confirm(`Rollback vers la version ${version} ?`)) return;
    await api.post('/api/versions/rollback', { version_id: id });
    load();
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-4xl mx-auto px-4 py-8 space-y-8">
        <h1 className="text-2xl font-bold text-gray-900">Versions &amp; Déploiements</h1>

        {/* Deploy form */}
        <div className="card p-6">
          <h2 className="font-semibold text-gray-900 mb-4">Déployer une nouvelle version</h2>
          <form onSubmit={handleDeploy} className="flex gap-3">
            <input type="text" className="input flex-1 font-mono" placeholder="v1.2.3" value={newVersion}
              onChange={(e) => setNewVersion(e.target.value)} required />
            <input type="text" className="input flex-1" placeholder="Notes (optionnel)" value={notes}
              onChange={(e) => setNotes(e.target.value)} />
            <button type="submit" className="btn-primary whitespace-nowrap" disabled={deploying}>
              {deploying ? 'Déploiement...' : '🚀 Déployer'}
            </button>
          </form>
        </div>

        {/* Version history */}
        <div className="card overflow-hidden">
          <div className="px-5 py-4 border-b border-gray-100 font-semibold text-gray-900">Historique des versions</div>
          <table className="w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Version</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Déployé par</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Date</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Notes</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {versions.map((v) => (
                <tr key={v.id} className={`hover:bg-gray-50 ${v.rolled_back_at ? 'opacity-50' : ''}`}>
                  <td className="px-4 py-3 font-mono font-semibold text-gray-900">{v.version}</td>
                  <td className="px-4 py-3 text-gray-600">{v.deployed_by}</td>
                  <td className="px-4 py-3 text-gray-600 whitespace-nowrap">{new Date(v.deployed_at).toLocaleString('fr-FR')}</td>
                  <td className="px-4 py-3 text-gray-500">{v.notes ?? '—'}</td>
                  <td className="px-4 py-3">
                    {!v.rolled_back_at && (
                      <button onClick={() => handleRollback(v.id, v.version)} className="text-xs text-orange-500 hover:underline">
                        Rollback
                      </button>
                    )}
                    {v.rolled_back_at && <span className="text-xs text-gray-400">Rolled back</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Migrations */}
        <div className="card overflow-hidden">
          <div className="px-5 py-4 border-b border-gray-100 font-semibold text-gray-900">Migrations BDD appliquées</div>
          <table className="w-full text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Fichier</th>
                <th className="text-left px-4 py-3 font-medium text-gray-600">Appliqué le</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {migrations.map((m) => (
                <tr key={m.id}>
                  <td className="px-4 py-3 font-mono text-xs text-gray-700">{m.filename}</td>
                  <td className="px-4 py-3 text-gray-600">{new Date(m.applied_at).toLocaleString('fr-FR')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
