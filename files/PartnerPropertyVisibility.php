<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

/**
 * Per-partner visibility of Lodgify properties, set from the "Associer des
 * biens" modal on /admin/partners:
 * - full:    the property behaves exactly as today (full details, rates,
 *            availability, booking).
 * - partial: everything is shown except rates/availability, which are
 *            replaced by a "contactez votre agence" message.
 * - none:    the property is entirely hidden from that partner.
 *
 * A property with no row for a given partner defaults to "full", so existing
 * partners keep seeing every property exactly as before this feature shipped.
 */
final class PartnerPropertyVisibility
{
    public const FULL = 'full';
    public const PARTIAL = 'partial';
    public const NONE = 'none';

    /** @return array<string, string> property_id => visibility */
    public static function allForPartner(int $partnerId): array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT property_id, visibility FROM partner_property_visibility WHERE partner_id = ?'
            );
            $stmt->execute([$partnerId]);
        } catch (Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['property_id']] = (string) $row['visibility'];
        }
        return $map;
    }

    public static function visibilityFor(?array $partner, int|string $propertyId): string
    {
        if (!$partner || empty($partner['id'])) {
            return self::FULL;
        }
        $map = self::allForPartner((int) $partner['id']);
        return $map[(string) $propertyId] ?? self::FULL;
    }

    /**
     * @param array<int|string, string> $visibilityByPropertyId property_id => full|partial|none
     */
    public static function save(int $partnerId, array $visibilityByPropertyId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM partner_property_visibility WHERE partner_id = ?')->execute([$partnerId]);
            $stmt = $pdo->prepare(
                'INSERT INTO partner_property_visibility (partner_id, property_id, visibility) VALUES (?, ?, ?)'
            );
            foreach ($visibilityByPropertyId as $propertyId => $visibility) {
                $visibility = (string) $visibility;
                // "full" is the implicit default: skip storing it so the
                // table only ever holds the exceptions (partial/none).
                if (!in_array($visibility, [self::PARTIAL, self::NONE], true)) {
                    continue;
                }
                $stmt->execute([$partnerId, (string) $propertyId, $visibility]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
