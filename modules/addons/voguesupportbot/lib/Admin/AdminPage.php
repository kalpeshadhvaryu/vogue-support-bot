<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Admin;

use VogueHosting\SupportBot\App;
use VogueHosting\SupportBot\Http\SystemUrl;
use VogueHosting\SupportBot\Knowledge\KnowledgeCache;
use VogueHosting\SupportBot\Logging\WhmcsLogger;
use VogueHosting\SupportBot\Phone\PhoneNormalizer;
use VogueHosting\SupportBot\Security\SessionCsrf;
use VogueHosting\SupportBot\Storage\ConversationStore;
use VogueHosting\SupportBot\Storage\Schema;
use VogueHosting\SupportBot\Whmcs\LocalApiGateway;

final class AdminPage
{
    /**
     * @param array<string, mixed> $vars
     */
    public static function render(array $vars): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            echo '<p>Admin session is not available.</p>';
            return;
        }
        $settings = App::settings();
        $notice = '';
        $error = '';
        try {
            Schema::ensure();
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (($_POST['action'] ?? '') === 'refresh')) {
                $token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '';
                if (!SessionCsrf::verify($_SESSION, 'voguesupportbot_admin_csrf', $token)) {
                    $error = 'The refresh request was rejected. Reload the page and try again.';
                } else {
                    $gateway = new LocalApiGateway(
                        $settings->apiAdminUsername(),
                        new PhoneNormalizer($settings->defaultCountryCode()),
                        new WhmcsLogger(),
                    );
                    $count = (new KnowledgeCache($gateway))->refresh();
                    $notice = 'Knowledge cache refreshed with ' . $count . ' public items.';
                }
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $base = SystemUrl::moduleBase();
        $webhook = $base . '/callback/whatsapp.php';
        $embed = '<script src="' . $base . '/assets/widget.js" data-endpoint="' . $base . '/callback/public-chat.php" data-channel="website" data-title="' . self::e($settings->widgetTitle()) . '" defer></script>';
        $csrf = SessionCsrf::issue($_SESSION, 'voguesupportbot_admin_csrf');
        $store = new ConversationStore();
        $viewId = isset($_GET['conversation']) ? (int) $_GET['conversation'] : 0;

        echo '<div class="vogue-support-bot">';
        echo '<h2>Vogue Support Bot</h2>';
        echo '<p>API keys and channel toggles are on the addon Configure tab. Nothing in this module hard-codes a secret.</p>';
        if ($notice !== '') {
            echo '<div class="alert alert-success">' . self::e($notice) . '</div>';
        }
        if ($error !== '') {
            echo '<div class="alert alert-danger">' . self::e($error) . '</div>';
        }
        if ($base === '/modules/addons/voguesupportbot') {
            echo '<div class="alert alert-warning">WHMCS System URL is empty, so the embed and webhook URLs below are incomplete. Set Configuration &gt; General Settings &gt; WHMCS System URL.</div>';
        }

        echo '<div class="row">';
        echo '<div class="col-md-6"><div class="panel panel-default"><div class="panel-heading"><strong>Channels</strong></div><div class="panel-body">';
        echo '<ul>';
        echo '<li>Client area widget: ' . self::onOff($settings->clientAreaEnabled()) . '</li>';
        echo '<li>Website embed: ' . self::onOff($settings->websiteEnabled()) . '</li>';
        echo '<li>WhatsApp (Interakt): ' . self::onOff($settings->whatsappEnabled()) . '</li>';
        echo '<li>Model: ' . self::e($settings->model()) . '</li>';
        echo '<li>Handoff department id: ' . ($settings->handoffDepartmentId() > 0 ? (string) $settings->handoffDepartmentId() : 'not set') . '</li>';
        echo '</ul>';
        echo '<form method="post">';
        echo '<input type="hidden" name="action" value="refresh">';
        echo '<input type="hidden" name="csrf" value="' . self::e($csrf) . '">';
        echo '<button class="btn btn-default" type="submit">Refresh knowledge cache</button>';
        echo '</form>';
        echo '<p class="help-block">Products come from GetProducts, announcements from GetAnnouncements, and public articles from tblknowledgebase. The cache is also refreshed when it is older than the configured TTL.</p>';
        echo '</div></div></div>';

        echo '<div class="col-md-6"><div class="panel panel-default"><div class="panel-heading"><strong>Install URLs</strong></div><div class="panel-body">';
        echo '<p><strong>Interakt webhook</strong></p>';
        echo '<input class="form-control" readonly value="' . self::e($webhook) . '">';
        echo '<p class="help-block">In Interakt, open Developer Settings, choose incoming customer messages, and paste the webhook secret from this addon.</p>';
        echo '<p><strong>voguehosting.com embed</strong></p>';
        echo '<textarea class="form-control" rows="4" readonly>' . self::e($embed) . '</textarea>';
        echo '<p class="help-block">Add https://voguehosting.com and https://www.voguehosting.com to Allowed origins. The client area origin is allowed automatically. The client area widget is injected by the addon hook and does not need this snippet.</p>';
        echo '</div></div></div>';
        echo '</div>';

        if ($viewId > 0) {
            self::renderConversation($store, $viewId);
        }
        self::renderList($store);
        echo '</div>';
    }

    private static function renderList(ConversationStore $store): void
    {
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Recent conversations</strong></div>';
        echo '<table class="table table-striped"><thead><tr><th>ID</th><th>Channel</th><th>Client</th><th>Identity</th><th>Handoff</th><th>Tokens</th><th></th></tr></thead><tbody>';
        $rows = $store->latest(40);
        if ($rows === []) {
            echo '<tr><td colspan="7">No conversations yet.</td></tr>';
        }
        foreach ($rows as $conversation) {
            $link = 'addonmodules.php?module=voguesupportbot&conversation=' . $conversation->id;
            echo '<tr>';
            echo '<td>' . $conversation->id . '</td>';
            echo '<td>' . self::e($conversation->channel) . '</td>';
            echo '<td>' . ($conversation->clientId === null ? '—' : (string) $conversation->clientId) . '</td>';
            echo '<td>' . self::e($conversation->identityLevel) . '</td>';
            echo '<td>' . self::e($conversation->handoffTicketTid ?? $conversation->handoffStatus) . '</td>';
            echo '<td>' . $conversation->tokenUsage . '</td>';
            echo '<td><a href="' . self::e($link) . '">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function renderConversation(ConversationStore $store, int $id): void
    {
        $conversation = $store->findById($id);
        echo '<div class="panel panel-default"><div class="panel-heading"><strong>Conversation ' . $id . '</strong></div><div class="panel-body">';
        if ($conversation === null) {
            echo '<p>That conversation does not exist.</p></div></div>';
            return;
        }
        echo '<p>Channel ' . self::e($conversation->channel) . ', client ' . ($conversation->clientId === null ? 'none' : (string) $conversation->clientId) . '.</p>';
        foreach ($store->messages($conversation->id, true) as $message) {
            $label = $message['role'];
            if ($message['tool_name'] !== '') {
                $label .= ' (' . $message['tool_name'] . ')';
            }
            echo '<p><strong>' . self::e($label) . '</strong><br><span style="white-space:pre-wrap">' . self::e($message['content']) . '</span></p>';
        }
        echo '</div></div>';
    }

    private static function onOff(bool $enabled): string
    {
        return $enabled ? '<span class="text-success">on</span>' : '<span class="text-muted">off</span>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
