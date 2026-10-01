<?php

if (!defined('WHMCS')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib/Autoload.php';

add_hook('ClientAreaFooterOutput', 1, static function (): string {
    try {
        $settings = VogueHosting\SupportBot\App::settings();
        $loggedIn = isset($_SESSION['uid']) && (is_int($_SESSION['uid']) || (is_string($_SESSION['uid']) && ctype_digit($_SESSION['uid'])))
            && (int) $_SESSION['uid'] > 0;
        $base = VogueHosting\SupportBot\Http\SystemUrl::moduleBase();
        if ($base === '/modules/addons/voguesupportbot') {
            return '';
        }
        $title = htmlspecialchars($settings->widgetTitle(), ENT_QUOTES, 'UTF-8');
        $greeting = htmlspecialchars($settings->widgetGreeting(), ENT_QUOTES, 'UTF-8');
        $script = htmlspecialchars($base . '/assets/widget.js', ENT_QUOTES, 'UTF-8');
        if ($loggedIn && $settings->clientAreaEnabled()) {
            $endpoint = htmlspecialchars($base . '/callback/client-chat.php', ENT_QUOTES, 'UTF-8');
            $csrf = htmlspecialchars(
                VogueHosting\SupportBot\Security\SessionCsrf::issue($_SESSION, 'voguesupportbot_csrf'),
                ENT_QUOTES,
                'UTF-8',
            );
            return '<script src="' . $script . '" data-endpoint="' . $endpoint . '" data-channel="clientarea" data-csrf="' . $csrf . '" data-title="' . $title . '" data-greeting="' . $greeting . '" defer></script>';
        }
        if (!$loggedIn && $settings->websiteEnabled()) {
            $endpoint = htmlspecialchars($base . '/callback/public-chat.php', ENT_QUOTES, 'UTF-8');
            return '<script src="' . $script . '" data-endpoint="' . $endpoint . '" data-channel="website" data-title="' . $title . '" data-greeting="' . $greeting . '" defer></script>';
        }
    } catch (Throwable $exception) {
        if (function_exists('logActivity')) {
            logActivity('Vogue Support Bot: widget hook failed: ' . $exception->getMessage());
        }
    }
    return '';
});
