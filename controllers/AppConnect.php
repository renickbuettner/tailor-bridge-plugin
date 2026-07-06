<?php namespace Renick\TailorCompanion\Controllers;

use ApplicationException;
use Backend\Classes\Controller;
use BackendAuth;
use BackendMenu;
use Date;
use Renick\TailorCompanion\Classes\Auth\PairingPayload;
use Renick\TailorCompanion\Classes\Auth\TokenManager;
use Renick\TailorCompanion\Models\AccessToken;
use Renick\TailorCompanion\Models\AuditLog;
use Renick\TailorCompanion\Models\Setting;
use System\Classes\SettingsManager;

/**
 * AppConnect backend page: list/create/revoke app connection tokens and
 * render the pairing QR code (payload: {v, url, login, token}).
 */
class AppConnect extends Controller
{
    /**
     * @var array requiredPermissions to view this page
     */
    public $requiredPermissions = ['renick.tailorcompanion.manage_tokens'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('Renick.TailorCompanion', 'appconnect');
    }

    public function index()
    {
        $this->pageTitle = 'App Connect';
        $this->vars['tokens'] = $this->listTokens();
    }

    /**
     * index_onCreateToken issues a token for the signed-in admin and returns
     * the pairing partial (QR + raw token, shown exactly once).
     */
    public function index_onCreateToken()
    {
        $user = BackendAuth::getUser();

        $name = trim((string) post('device_name')) ?: null;

        $expiryDays = (int) Setting::get('token_expiry_days', 0);
        $expiresAt = $expiryDays > 0 ? Date::now()->addDays($expiryDays) : null;

        $result = (new TokenManager)->issue($user, $name, $expiresAt);

        AuditLog::record('token_issued', [
            'token_id' => $result['model']->id,
            'backend_user_id' => $user->id,
        ]);

        $this->vars['rawToken'] = $result['token'];
        $this->vars['payloadJson'] = PairingPayload::toJson($user, $result['token']);
        $this->vars['model'] = $result['model'];
        $this->vars['tokens'] = $this->listTokens();

        return [
            '#pairingResult' => $this->makePartial('pairing_result'),
            '#tokenList' => $this->makePartial('token_list'),
        ];
    }

    /**
     * index_onRevokeToken disables a token permanently.
     */
    public function index_onRevokeToken()
    {
        $token = AccessToken::find((int) post('token_id'));
        if (!$token) {
            throw new ApplicationException('Token not found.');
        }

        (new TokenManager)->revoke($token);

        AuditLog::record('token_revoked', [
            'token_id' => $token->id,
            'backend_user_id' => BackendAuth::getUser()?->id,
        ]);

        $this->vars['tokens'] = $this->listTokens();

        return ['#tokenList' => $this->makePartial('token_list')];
    }

    protected function listTokens()
    {
        return AccessToken::with('user')->orderByDesc('created_at')->get();
    }
}
