import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import Navbar from '../../components/Navbar';
import api from '../../api';
import { Partner } from '@samchlolaurepartners/shared';

export default function AdminPartners() {
  const [partners, setPartners] = useState<Partner[]>([]);
  const [loading, setLoading] = useState(true);

  const load = () => {
    api.get<{ data: Partner[] }>('/api/partners')
      .then((res) => setPartners(res.data.data))
      .catch(console.error)
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const handleDelete = async (id: number, name: string) => {
    if (!confirm(`Désactiver le partenaire "${name}" ?`)) return;
    await api.delete(`/api/partners/${id}`);
    load();
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-5xl mx-auto px-4 py-8">
        <div className="flex items-center justify-between mb-6">
          <h1 className="text-2xl font-bold text-gray-900">Gestion des partenaires</h1>
          <Link to="/admin/partners/new" className="btn-primary">+ Nouveau partenaire</Link>
        </div>

        {loading ? (
          <div className="text-center py-12"><div className="animate-spin h-8 w-8 border-b-2 border-brand-500 rounded-full mx-auto" /></div>
        ) : (
          <div className="card overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-100">
                <tr>
                  <th className="text-left px-4 py-3 font-medium text-gray-600">Partenaire</th>
                  <th className="text-left px-4 py-3 font-medium text-gray-600">Sous-domaine</th>
                  <th className="text-left px-4 py-3 font-medium text-gray-600">Email</th>
                  <th className="text-right px-4 py-3 font-medium text-gray-600">Marge %</th>
                  <th className="text-center px-4 py-3 font-medium text-gray-600">Actif</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50">
                {partners.map((p) => (
                  <tr key={p.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-medium text-gray-900">{p.name}</td>
                    <td className="px-4 py-3 text-gray-600 font-mono text-xs">{p.subdomain}</td>
                    <td className="px-4 py-3 text-gray-600">{p.email}</td>
                    <td className="px-4 py-3 text-right font-semibold text-gray-900">{p.markup_percent}%</td>
                    <td className="px-4 py-3 text-center">{p.active ? '✅' : '❌'}</td>
                    <td className="px-4 py-3 text-right">
                      <Link to={`/admin/partners/${p.id}/edit`} className="text-brand-500 hover:underline mr-3">Éditer</Link>
                      <button onClick={() => handleDelete(p.id, p.name)} className="text-red-500 hover:underline">Supprimer</button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
