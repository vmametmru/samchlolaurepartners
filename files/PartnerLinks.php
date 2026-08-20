<?php

declare(strict_types=1);

namespace App;

/**
 * Links between partner accounts (db/migrations/057_create_partner_links.sql).
 *
 * Set up by an admin from the "Lier" button on /admin/partners, this lets a
 * logged-in partner user instantly switch into any linked partner's own
 * account — a silent logout/login into the other account (see
 * PageController::partnerSwitchAccount()), no separate credentials needed —
 * via a small "linked accounts" menu in the navbar.
 *
 * Each pair is stored once, canonically ordered (the smaller partner id
 * first), so linking A→B and B→A are the exact same row: the relation is
 * always symmetric.
 */
final class PartnerLinks
{
    /**
     * Replaces ALL links involving $partnerId with the given set of other
     * partner ids. Called from the admin "Lier" dialog, which always
     * submits the full desired set of checked partners (not an incremental
     * add/remove), so the simplest correct approach is delete-then-reinsert.
     */
    public static function setLinks(int $partnerId, array $linkedPartnerIds): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM partner_links WHERE partner_id_a = ? OR partner_id_b = ?')
            ->execute([$partnerId, $partnerId]);

        $stmt = $pdo->prepare('INSERT IGNORE INTO partner_links (partner_id_a, partner_id_b) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $linkedPartnerIds)) as $otherId) {
            if ($otherId === $partnerId || $otherId <= 0) {
                continue;
            }
            [$a, $b] = $partnerId < $otherId ? [$partnerId, $otherId] : [$otherId, $partnerId];
            $stmt->execute([$a, $b]);
        }
    }

    /**
     * @return int[] Partner ids linked to $partnerId (checking both sides of the pair).
     */
    public static function linkedPartnerIds(int $partnerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT partner_id_a, partner_id_b FROM partner_links WHERE partner_id_a = ? OR partner_id_b = ?'
        );
        $stmt->execute([$partnerId, $partnerId]);
        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $a = (int) $row['partner_id_a'];
            $b = (int) $row['partner_id_b'];
            $ids[] = $a === $partnerId ? $b : $a;
        }
        return $ids;
    }

    /**
     * Full partner rows (id, name, subdomain) linked to $partnerId, ordered
     * by name — used both to pre-check the admin dialog's checkboxes (see
     * PageController::adminPartners()) and to populate the partner-facing
     * "linked accounts" switcher in the navbar.
     *
     * @return array<int, array{id: int, name: string, subdomain: ?string}>
     */
    public static function linkedPartners(int $partnerId): array
    {
        $ids = self::linkedPartnerIds($partnerId);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT id, name, subdomain FROM partners WHERE id IN ($placeholders) ORDER BY name"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Whether $partnerId and $otherPartnerId are linked — used to authorize
     * PageController::partnerSwitchAccount() so a partner can only switch
     * into an account an admin has explicitly linked, never an arbitrary
     * partner id typed into the URL.
     */
    public static function areLinked(int $partnerId, int $otherPartnerId): bool
    {
        if ($partnerId === $otherPartnerId) {
            return false;
        }
        [$a, $b] = $partnerId < $otherPartnerId ? [$partnerId, $otherPartnerId] : [$otherPartnerId, $partnerId];
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM partner_links WHERE partner_id_a = ? AND partner_id_b = ? LIMIT 1'
        );
        $stmt->execute([$a, $b]);
        return (bool) $stmt->fetchColumn();
    }
}
