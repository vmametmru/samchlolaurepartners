<?php

declare(strict_types=1);

namespace App;

/**
 * Links between partner accounts (db/migrations/057_create_partner_links.sql,
 * db/migrations/063_add_link_type_to_partner_links.sql).
 *
 * Set up by an admin from the "Lier" button on /admin/partners, this lets a
 * logged-in partner user instantly switch into another partner's own
 * account — a silent logout/login into the other account (see
 * PageController::partnerSwitchAccount()), no separate credentials needed —
 * via a small "linked accounts" menu in the navbar.
 *
 * There are two kinds of link:
 *  - "direct": symmetric, exactly like before this pair of link types was
 *    introduced. Either partner can switch into the other's account.
 *  - "hierarchical": directional. One partner (principal_partner_id) can
 *    switch into the other ("child") account, but the child gets no switch
 *    button at all for this pair and cannot switch into the principal, nor
 *    into any of the principal's other children.
 *
 * Each pair is stored once, canonically ordered (the smaller partner id
 * first) so a given unordered pair only ever has a single row, but for
 * hierarchical links principal_partner_id explicitly records which side of
 * the pair is the principal (independent of that canonical a/b ordering).
 */
final class PartnerLinks
{
    /**
     * Replaces ALL links involving $partnerId with the given set of links.
     * Called from the admin "Lier" dialog, which always submits the full
     * desired set of links (not an incremental add/remove), so the simplest
     * correct approach is delete-then-reinsert. Any "hierarchical" entry
     * makes $partnerId the principal over that other partner.
     *
     * @param array<int, array{id: int, type: string}> $links
     */
    public static function setLinks(int $partnerId, array $links): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM partner_links WHERE partner_id_a = ? OR partner_id_b = ?')
            ->execute([$partnerId, $partnerId]);

        $stmt = $pdo->prepare(
            'INSERT INTO partner_links (partner_id_a, partner_id_b, link_type, principal_partner_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE link_type = VALUES(link_type), principal_partner_id = VALUES(principal_partner_id)'
        );
        $seen = [];
        foreach ($links as $link) {
            $otherId = (int) ($link['id'] ?? 0);
            $type = ($link['type'] ?? 'direct') === 'hierarchical' ? 'hierarchical' : 'direct';
            if ($otherId === $partnerId || $otherId <= 0 || isset($seen[$otherId])) {
                continue;
            }
            $seen[$otherId] = true;
            [$a, $b] = $partnerId < $otherId ? [$partnerId, $otherId] : [$otherId, $partnerId];
            $principalId = $type === 'hierarchical' ? $partnerId : null;
            $stmt->execute([$a, $b, $type, $principalId]);
        }
    }

    /**
     * Every raw partner_links row involving $partnerId, regardless of
     * direction/type — used to pre-fill the admin "Lier" dialog's per-row
     * radio choice (Aucune / Directe / Hiérarchique).
     *
     * @return array<int, array{other_id: int, type: string, is_principal: bool}>
     */
    public static function rawLinksFor(int $partnerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT partner_id_a, partner_id_b, link_type, principal_partner_id
             FROM partner_links WHERE partner_id_a = ? OR partner_id_b = ?'
        );
        $stmt->execute([$partnerId, $partnerId]);
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $a = (int) $row['partner_id_a'];
            $b = (int) $row['partner_id_b'];
            $otherId = $a === $partnerId ? $b : $a;
            $principalId = $row['principal_partner_id'] !== null ? (int) $row['principal_partner_id'] : null;
            $result[$otherId] = [
                'other_id' => $otherId,
                'type' => (string) $row['link_type'],
                'is_principal' => $principalId === $partnerId,
            ];
        }
        return array_values($result);
    }

    /**
     * @return int[] Partner ids linked to $partnerId (checking both sides of the pair), any type.
     */
    public static function linkedPartnerIds(int $partnerId): array
    {
        return array_map(static fn (array $link): int => $link['other_id'], self::rawLinksFor($partnerId));
    }

    /**
     * @return int[] Partner ids that are hierarchical "children" of $partnerId,
     * i.e. $partnerId is their principal. Used both to grant the principal
     * visibility over its children's analytics data (AnalyticsController)
     * and, more generally, anywhere a principal needs to know which
     * accounts it oversees.
     */
    public static function childPartnerIds(int $partnerId): array
    {
        $ids = [];
        foreach (self::rawLinksFor($partnerId) as $link) {
            if ($link['type'] === 'hierarchical' && $link['is_principal']) {
                $ids[] = $link['other_id'];
            }
        }
        return $ids;
    }

    /**
     * @return int[] Partner ids that are hierarchical "principals" (parents)
     * of $partnerId, i.e. $partnerId is one of their children. Used by
     * App\PackageRequests to look up whether $partnerId's parent has opted
     * into forcing its own offer requests onto $partnerId's page (see
     * partners.packages_force_child_visibility,
     * db/migrations/068_create_package_requests.sql).
     */
    public static function principalPartnerIds(int $partnerId): array
    {
        $ids = [];
        foreach (self::rawLinksFor($partnerId) as $link) {
            if ($link['type'] === 'hierarchical' && !$link['is_principal']) {
                $ids[] = $link['other_id'];
            }
        }
        return $ids;
    }

    /**
     * Full partner rows (id, name, subdomain) that $partnerId can actually
     * SWITCH INTO — used both to populate the partner-facing "linked
     * accounts" switcher in the navbar and to authorize
     * PageController::partnerSwitchAccount(). This includes every "direct"
     * link (symmetric) and every "hierarchical" link where $partnerId is
     * the principal, but excludes "hierarchical" links where $partnerId is
     * the child (a child cannot switch into its principal nor its
     * siblings, and gets no entries here at all for that relationship).
     *
     * @return array<int, array{id: int, name: string, subdomain: ?string}>
     */
    public static function linkedPartners(int $partnerId): array
    {
        $ids = [];
        foreach (self::rawLinksFor($partnerId) as $link) {
            if ($link['type'] === 'hierarchical' && !$link['is_principal']) {
                continue;
            }
            $ids[] = $link['other_id'];
        }
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
     * Full partner rows $partnerId's "linked accounts" navbar menu should
     * offer, given the account it actually logged into originally
     * ($homePartnerId — see Auth's home_partner_id session claim). While
     * browsing its OWN account ($homePartnerId === $partnerId) this is just
     * linkedPartners($partnerId), same as before. But once switched into a
     * hierarchical CHILD account, $partnerId alone would see nothing (a
     * child has no switch targets of its own by design) — so instead this
     * offers the same accounts $homePartnerId itself could reach (its
     * siblings/children), plus $homePartnerId itself so the user can switch
     * back, fixing the "stuck as a child" navigation dead-end.
     *
     * @return array<int, array{id: int, name: string, subdomain: ?string}>
     */
    public static function switchableAccounts(int $partnerId, int $homePartnerId): array
    {
        if ($homePartnerId === $partnerId) {
            return self::linkedPartners($partnerId);
        }

        $accounts = [];
        foreach (self::linkedPartners($homePartnerId) as $partner) {
            if ((int) $partner['id'] !== $partnerId) {
                $accounts[(int) $partner['id']] = $partner;
            }
        }
        $stmt = Database::connection()->prepare('SELECT id, name, subdomain FROM partners WHERE id = ? LIMIT 1');
        $stmt->execute([$homePartnerId]);
        $home = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($home) {
            $accounts[$homePartnerId] = $home;
        }

        $list = array_values($accounts);
        usort($list, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        return $list;
    }

    /**
     * Whether a session currently acting as $partnerId (having originally
     * logged into $homePartnerId) is authorized to switch into
     * $targetPartnerId — the same rule set as switchableAccounts(), used to
     * authorize PageController::partnerSwitchAccount() so a child session
     * can only reach accounts its home account could reach (or the home
     * account itself), never an arbitrary id typed into the URL.
     */
    public static function canSwitchTo(int $partnerId, int $homePartnerId, int $targetPartnerId): bool
    {
        if ($homePartnerId === $partnerId) {
            return self::areLinked($partnerId, $targetPartnerId);
        }
        if ($targetPartnerId === $homePartnerId) {
            return true;
        }
        return self::areLinked($homePartnerId, $targetPartnerId);
    }

    /**
     * Computes the home_partner_id the NEW session should carry after a
     * switch from $partnerId (whose session's current home is
     * $homePartnerId) into $targetPartnerId. Returns null once back at the
     * genuinely-logged-in account (whether by switching back to it, or via
     * a "direct"/symmetric link — those stay mutually reachable on their
     * own, same as before this hierarchical home-tracking existed), and
     * otherwise keeps tracking the same home so navigation among a
     * principal's children keeps working across multiple hops.
     */
    public static function homeAfterSwitch(int $partnerId, int $homePartnerId, int $targetPartnerId): ?int
    {
        if ($targetPartnerId === $homePartnerId) {
            return null;
        }
        if ($homePartnerId !== $partnerId) {
            return $homePartnerId;
        }
        foreach (self::rawLinksFor($partnerId) as $link) {
            if ($link['other_id'] === $targetPartnerId) {
                return ($link['type'] === 'hierarchical' && $link['is_principal']) ? $partnerId : null;
            }
        }
        return null;
    }

    /**
     * Whether $partnerId can switch into $otherPartnerId's account — used to
     * authorize PageController::partnerSwitchAccount() so a partner can only
     * switch into an account an admin has explicitly linked (and, for
     * hierarchical links, only in the principal → child direction), never
     * an arbitrary partner id typed into the URL.
     */
    public static function areLinked(int $partnerId, int $otherPartnerId): bool
    {
        if ($partnerId === $otherPartnerId) {
            return false;
        }
        [$a, $b] = $partnerId < $otherPartnerId ? [$partnerId, $otherPartnerId] : [$otherPartnerId, $partnerId];
        $stmt = Database::connection()->prepare(
            'SELECT link_type, principal_partner_id FROM partner_links WHERE partner_id_a = ? AND partner_id_b = ? LIMIT 1'
        );
        $stmt->execute([$a, $b]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ((string) $row['link_type'] === 'hierarchical') {
            return $row['principal_partner_id'] !== null && (int) $row['principal_partner_id'] === $partnerId;
        }
        return true;
    }
}
