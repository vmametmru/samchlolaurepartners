import { LodgifyAvailabilityDay } from '@samchlolaurepartners/shared';

interface Props {
  availability: LodgifyAvailabilityDay[];
  month?: Date;
}

function getDaysInMonth(year: number, month: number): number {
  return new Date(year, month + 1, 0).getDate();
}

function getFirstDayOfMonth(year: number, month: number): number {
  return new Date(year, month, 1).getDay();
}

export default function AvailabilityCalendar({ availability, month = new Date() }: Props) {
  const year = month.getFullYear();
  const monthIdx = month.getMonth();
  const daysInMonth = getDaysInMonth(year, monthIdx);
  const firstDay = getFirstDayOfMonth(year, monthIdx);

  const availabilityMap = new Map(
    availability.map((d) => [d.date, d.available])
  );

  const monthStr = month.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });

  const cells: React.ReactNode[] = [];

  // Empty cells for alignment
  for (let i = 0; i < firstDay; i++) {
    cells.push(<div key={`empty-${i}`} />);
  }

  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = `${year}-${String(monthIdx + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const isAvailable = availabilityMap.get(dateStr);
    const isPast = new Date(dateStr) < new Date(new Date().toDateString());

    cells.push(
      <div
        key={day}
        className={`aspect-square flex items-center justify-center text-sm rounded-lg font-medium
          ${isPast ? 'text-gray-300 cursor-default' : ''}
          ${!isPast && isAvailable === true ? 'bg-green-50 text-green-700 border border-green-200' : ''}
          ${!isPast && isAvailable === false ? 'bg-red-50 text-red-400 line-through' : ''}
          ${!isPast && isAvailable === undefined ? 'text-gray-600' : ''}
        `}
      >
        {day}
      </div>
    );
  }

  return (
    <div>
      <h3 className="text-center font-semibold text-gray-700 mb-3 capitalize">{monthStr}</h3>
      <div className="grid grid-cols-7 gap-1 text-center">
        {['D', 'L', 'M', 'M', 'J', 'V', 'S'].map((d, i) => (
          <div key={i} className="text-xs text-gray-400 font-medium py-1">{d}</div>
        ))}
        {cells}
      </div>
      <div className="mt-3 flex gap-4 text-xs text-gray-500">
        <span className="flex items-center gap-1">
          <span className="w-3 h-3 rounded bg-green-100 border border-green-200 inline-block" />
          Disponible
        </span>
        <span className="flex items-center gap-1">
          <span className="w-3 h-3 rounded bg-red-100 inline-block" />
          Indisponible
        </span>
      </div>
    </div>
  );
}
