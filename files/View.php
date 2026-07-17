<?php

declare(strict_types=1);

namespace App;

final class View
{
    public static function render(string $template, array $data = [], string $layout = 'layout'): void
    {
        $viewsPath = BASE_PATH . '/files/views';
        $templatePath = $viewsPath . '/' . $template . '.php';
        $layoutPath = $viewsPath . '/' . $layout . '.php';
        if (!is_file($templatePath)) {
            throw new HttpException(500, 'Internal Server Error', 'View not found: ' . $template);
        }

        // The "/" gate page (pages/enter-code) must always render as if no
        // partner were active — title "Portail Partenaires" and no public
        // pages menu — even if a partner_code cookie is still set from a
        // previous session; a stale cookie must never leak partner branding
        // onto the code-entry gate. Pass 'suppressPartner' => true from the
        // controller to force that.
        $suppressPartner = !empty($data['suppressPartner']);
        unset($data['suppressPartner']);
        $partner = $suppressPartner ? null : Tenant::currentPublic();
        $user = Auth::user();
        $authDebug = $user === null ? Auth::debugStatus() : null;
        $flash = Flash::pull();
        $pageTitle = $data['pageTitle'] ?? 'samchlolaurepartners';
        extract($data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $content = (string) ob_get_clean();
        require $layoutPath;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function badgeLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'En attente',
            'confirmed' => 'Confirmée',
            'cancelled' => 'Annulée',
            default => $status,
        };
    }
}
