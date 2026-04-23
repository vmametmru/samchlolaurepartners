import { useState, useEffect } from 'react';
import Navbar from '../../components/Navbar';
import api from '../../api';
import { Partner } from '@samchlolaurepartners/shared';
import { useAuth } from '../../context/AuthContext';

export default function PartnerSettings() {
  const { user } = useAuth();
  const [partner, setPartner] = useState<Partial<Partner>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api.get<{ data: Partner }>(`/api/partners/${user?.partner_id}`)
      .then((res) => setPartner(res.data.data))
      .catch(console.error)
      .finally(() => setLoading(false));
  }, [user]);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      await api.put(`/api/partners/${user?.partner_id}`, partner);
      setSaved(true);
      setTimeout(() => setSaved(false), 2000);
    } catch {
      alert('Erreur lors de la sauvegarde');
    } finally {
      setSaving(false);
    }
  };

  if (loading) return <div className="min-h-screen flex items-center justify-center"><div className="animate-spin h-8 w-8 border-b-2 border-brand-500 rounded-full" /></div>;

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-2xl mx-auto px-4 py-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Paramètres du compte</h1>

        <form onSubmit={handleSave} className="card p-6 space-y-5">
          <div>
            <label className="label">Nom du partenaire</label>
            <input type="text" className="input" value={partner.name ?? ''} onChange={(e) => setPartner({ ...partner, name: e.target.value })} />
          </div>
          <div>
            <label className="label">Email de contact</label>
            <input type="email" className="input" value={partner.email ?? ''} onChange={(e) => setPartner({ ...partner, email: e.target.value })} />
          </div>
          <div>
            <label className="label">URL du logo</label>
            <input type="url" className="input" value={partner.logo_url ?? ''} onChange={(e) => setPartner({ ...partner, logo_url: e.target.value })} />
          </div>
          <div>
            <label className="label">Couleur principale</label>
            <div className="flex items-center gap-3">
              <input type="color" value={partner.primary_color ?? '#E61E4D'} onChange={(e) => setPartner({ ...partner, primary_color: e.target.value })} className="h-10 w-20 rounded cursor-pointer" />
              <input type="text" className="input" value={partner.primary_color ?? '#E61E4D'} onChange={(e) => setPartner({ ...partner, primary_color: e.target.value })} />
            </div>
          </div>

          <hr className="border-gray-100" />
          <h2 className="font-semibold text-gray-900">Configuration SMTP</h2>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Hôte SMTP</label>
              <input type="text" className="input" value={partner.smtp_host ?? ''} onChange={(e) => setPartner({ ...partner, smtp_host: e.target.value })} />
            </div>
            <div>
              <label className="label">Port SMTP</label>
              <input type="number" className="input" value={partner.smtp_port ?? ''} onChange={(e) => setPartner({ ...partner, smtp_port: parseInt(e.target.value, 10) })} />
            </div>
            <div>
              <label className="label">Utilisateur SMTP</label>
              <input type="text" className="input" value={partner.smtp_user ?? ''} onChange={(e) => setPartner({ ...partner, smtp_user: e.target.value })} />
            </div>
            <div>
              <label className="label">Mot de passe SMTP</label>
              <input type="password" className="input" value={partner.smtp_pass ?? ''} onChange={(e) => setPartner({ ...partner, smtp_pass: e.target.value })} />
            </div>
          </div>

          <button type="submit" className="btn-primary" disabled={saving}>
            {saving ? 'Sauvegarde...' : saved ? '✓ Sauvegardé' : 'Sauvegarder'}
          </button>
        </form>
      </div>
    </div>
  );
}
