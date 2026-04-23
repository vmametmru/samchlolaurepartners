import { GuestNationality } from '@samchlolaurepartners/shared';

interface Props {
  adults: number;
  children: number;
  guests: GuestNationality[];
  onChange: (guests: GuestNationality[]) => void;
}

const NATIONALITIES = [
  'Mauricienne', 'Française', 'Britannique', 'Allemande', 'Italienne',
  'Espagnole', 'Belge', 'Suisse', 'Américaine', 'Australienne', 'Autre',
];

export default function NationalityInput({ adults, children, guests, onChange }: Props) {
  const total = adults + children;
  const sameForAll = guests.length > 0 && guests.every((g) => g.nationality === guests[0].nationality);

  const handleSameForAll = (nat: string) => {
    const updated: GuestNationality[] = [
      ...Array(adults).fill(null).map(() => ({ type: 'adult' as const, nationality: nat })),
      ...Array(children).fill(null).map(() => ({ type: 'child' as const, nationality: nat })),
    ];
    onChange(updated);
  };

  const handleIndividual = (index: number, nationality: string) => {
    const updated = [...guests];
    if (updated[index]) {
      updated[index] = { ...updated[index], nationality };
    }
    onChange(updated);
  };

  const ensureGuestsPopulated = () => {
    if (guests.length !== total) {
      const updated: GuestNationality[] = [
        ...Array(adults).fill(null).map((_, i) => ({
          type: 'adult' as const,
          nationality: guests[i]?.nationality ?? '',
        })),
        ...Array(children).fill(null).map((_, i) => ({
          type: 'child' as const,
          nationality: guests[adults + i]?.nationality ?? '',
        })),
      ];
      onChange(updated);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <input
          type="checkbox"
          id="sameNat"
          checked={sameForAll && guests.length === total}
          onChange={(e) => {
            if (e.target.checked) {
              handleSameForAll(guests[0]?.nationality ?? '');
            } else {
              ensureGuestsPopulated();
            }
          }}
          className="rounded"
        />
        <label htmlFor="sameNat" className="text-sm text-gray-700">
          Même nationalité pour tous
        </label>
      </div>

      {sameForAll && guests.length === total ? (
        <div>
          <label className="label">Nationalité (tous)</label>
          <select
            className="input"
            value={guests[0]?.nationality ?? ''}
            onChange={(e) => handleSameForAll(e.target.value)}
          >
            <option value="">Sélectionner...</option>
            {NATIONALITIES.map((n) => <option key={n} value={n}>{n}</option>)}
          </select>
        </div>
      ) : (
        <div className="space-y-2">
          {Array(adults).fill(null).map((_, i) => (
            <div key={`adult-${i}`}>
              <label className="label">Adulte {i + 1} — Nationalité</label>
              <select
                className="input"
                value={guests[i]?.nationality ?? ''}
                onChange={(e) => handleIndividual(i, e.target.value)}
              >
                <option value="">Sélectionner...</option>
                {NATIONALITIES.map((n) => <option key={n} value={n}>{n}</option>)}
              </select>
            </div>
          ))}
          {Array(children).fill(null).map((_, i) => (
            <div key={`child-${i}`}>
              <label className="label">Enfant {i + 1} — Nationalité</label>
              <select
                className="input"
                value={guests[adults + i]?.nationality ?? ''}
                onChange={(e) => handleIndividual(adults + i, e.target.value)}
              >
                <option value="">Sélectionner...</option>
                {NATIONALITIES.map((n) => <option key={n} value={n}>{n}</option>)}
              </select>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
