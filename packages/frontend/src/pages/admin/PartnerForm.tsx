import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import Navbar from '../../components/Navbar';
import api from '../../api';
import { Partner } from '@samchlolaurepartners/shared';

export default function AdminPartnerForm() {
  const { id } = useParams<{ id?: string }>();
  const navigate = useNavigate();
  const isEdit = Boolean(id);

  const [form, setForm] = useState<Partial<Partner>>({
    primary_color: '#E61E4D',
    markup_percent: 0,
    active: true,
  });
  const [loading, setLoading] = useState(isEdit);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!isEdit) return;
    api.get<{ data: Partner }>(`/api/partners/${id}`)
      .then((res) => setForm(res.data.data))
      .catch(console.error)
      .finally(() => setLoading(false));
  }, [id, isEdit]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (isEdit) {
        await api.put(`/api/partners/${id}`, form);
      } else {
        await api.post('/api/partners', form);
      }
      navigate('/admin/partners');
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
        <h1 className="text-2xl font-bold text-gray-900 mb-6">
          {isEdit ? 'Modifier le partenaire' : 'Nouveau partenaire'}
        </h1>

        <form onSubmit={handleSubmit} className="card p-6 space-y-5">
          <div>
            <label className="label">Nom *</label>
            <input type="text" className="input" value={form.name ?? ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </div>
          <div>
            <label className="label">Sous-domaine *</label>
            <input type="text" className="input font-mono" placeholder="partner1" value={form.subdomain ?? ''} onChange={(e) => setForm({ ...form, subdomain: e.target.value })} required disabled={isEdit} />
          </div>
          <div>
            <label className="label">Email de contact *</label>
            <input type="email" className="input" value={form.email ?? ''} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
          </div>
          <div>
            <label className="label">Marge % *</label>
            <input type="number" className="input" min={0} max={100} step={0.5} value={form.markup_percent ?? 0} onChange={(e) => setForm({ ...form, markup_percent: parseFloat(e.target.value) })} />
          </div>
          <div>
            <label className="label">URL du logo</label>
            <input type="url" className="input" value={form.logo_url ?? ''} onChange={(e) => setForm({ ...form, logo_url: e.target.value })} />
          </div>
          <div>
            <label className="label">Couleur principale</label>
            <div className="flex items-center gap-3">
              <input type="color" value={form.primary_color ?? '#E61E4D'} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} className="h-10 w-20 rounded cursor-pointer" />
              <input type="text" className="input" value={form.primary_color ?? '#E61E4D'} onChange={(e) => setForm({ ...form, primary_color: e.target.value })} />
            </div>
          </div>

          <hr className="border-gray-100" />
          <h2 className="font-semibold text-gray-900">SMTP</h2>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Hôte</label>
              <input type="text" className="input" value={form.smtp_host ?? ''} onChange={(e) => setForm({ ...form, smtp_host: e.target.value })} />
            </div>
            <div>
              <label className="label">Port</label>
              <input type="number" className="input" value={form.smtp_port ?? ''} onChange={(e) => setForm({ ...form, smtp_port: parseInt(e.target.value, 10) })} />
            </div>
            <div>
              <label className="label">Utilisateur</label>
              <input type="text" className="input" value={form.smtp_user ?? ''} onChange={(e) => setForm({ ...form, smtp_user: e.target.value })} />
            </div>
            <div>
              <label className="label">Mot de passe</label>
              <input type="password" className="input" value={form.smtp_pass ?? ''} onChange={(e) => setForm({ ...form, smtp_pass: e.target.value })} />
            </div>
          </div>

          <div className="flex items-center gap-2">
            <input type="checkbox" id="active" checked={form.active !== false} onChange={(e) => setForm({ ...form, active: e.target.checked })} />
            <label htmlFor="active" className="text-sm text-gray-700">Partenaire actif</label>
          </div>

          <div className="flex gap-3">
            <button type="submit" className="btn-primary" disabled={saving}>{saving ? 'Sauvegarde...' : 'Sauvegarder'}</button>
            <button type="button" className="btn-secondary" onClick={() => navigate('/admin/partners')}>Annuler</button>
          </div>
        </form>
      </div>
    </div>
  );
}
