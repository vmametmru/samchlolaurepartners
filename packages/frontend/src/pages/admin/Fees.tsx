import { useEffect, useState } from 'react';
import Navbar from '../../components/Navbar';
import api from '../../api';
import { TouristTax, CleaningFee } from '@samchlolaurepartners/shared';

export default function AdminFees() {
  const [tax, setTax] = useState<Partial<TouristTax>>({ per_person_per_night: 0, applies_to_foreigners_only: true, applies_to_children: false });
  const [cleaningFees, setCleaningFees] = useState<CleaningFee[]>([]);
  const [savingTax, setSavingTax] = useState(false);
  const [taxSaved, setTaxSaved] = useState(false);

  useEffect(() => {
    api.get<{ data: TouristTax }>('/api/fees/tourist-tax')
      .then((res) => { if (res.data.data) setTax(res.data.data); })
      .catch(console.error);

    api.get<{ data: CleaningFee[] }>('/api/fees/cleaning')
      .then((res) => setCleaningFees(res.data.data))
      .catch(console.error);
  }, []);

  const saveTax = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingTax(true);
    try {
      await api.put('/api/fees/tourist-tax', tax);
      setTaxSaved(true);
      setTimeout(() => setTaxSaved(false), 2000);
    } finally {
      setSavingTax(false);
    }
  };

  return (
    <div className="min-h-screen bg-gray-50">
      <Navbar />
      <div className="max-w-3xl mx-auto px-4 py-8 space-y-8">
        <h1 className="text-2xl font-bold text-gray-900">Frais &amp; Taxes</h1>

        {/* Tourist Tax */}
        <div className="card p-6">
          <h2 className="font-semibold text-gray-900 mb-4">Taxe touristique</h2>
          <form onSubmit={saveTax} className="space-y-4">
            <div>
              <label className="label">Tarif par personne / nuit (€)</label>
              <input type="number" step="0.01" className="input max-w-xs" value={tax.per_person_per_night ?? 0}
                onChange={(e) => setTax({ ...tax, per_person_per_night: parseFloat(e.target.value) })} />
            </div>
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <input type="checkbox" id="foreignOnly" checked={tax.applies_to_foreigners_only ?? false}
                  onChange={(e) => setTax({ ...tax, applies_to_foreigners_only: e.target.checked })} />
                <label htmlFor="foreignOnly" className="text-sm text-gray-700">Applicable aux étrangers uniquement (non-Mauriciens)</label>
              </div>
              <div className="flex items-center gap-2">
                <input type="checkbox" id="appChildren" checked={tax.applies_to_children ?? false}
                  onChange={(e) => setTax({ ...tax, applies_to_children: e.target.checked })} />
                <label htmlFor="appChildren" className="text-sm text-gray-700">Applicable aux enfants (&lt;12 ans)</label>
              </div>
            </div>
            <button type="submit" className="btn-primary" disabled={savingTax}>
              {savingTax ? 'Sauvegarde...' : taxSaved ? '✓ Sauvegardé' : 'Sauvegarder'}
            </button>
          </form>
        </div>

        {/* Cleaning fees */}
        <div className="card p-6">
          <h2 className="font-semibold text-gray-900 mb-4">Frais de nettoyage</h2>
          {cleaningFees.length === 0 ? (
            <p className="text-sm text-gray-400">Aucun frais configuré. Utilisez l'API PUT /api/fees/cleaning/:propertyId pour en ajouter.</p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr>
                  <th className="text-left py-2 text-gray-600">Hébergement</th>
                  <th className="text-right py-2 text-gray-600">Tarif (€/pers/nuit)</th>
                </tr>
              </thead>
              <tbody>
                {cleaningFees.map((f) => (
                  <tr key={f.id} className="border-t border-gray-50">
                    <td className="py-2 text-gray-700">{f.property_id ?? 'Par défaut'}</td>
                    <td className="py-2 text-right font-medium">{f.per_person_per_night}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>
    </div>
  );
}
